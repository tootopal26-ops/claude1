<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Env;
use App\Core\HttpException;
use App\Services\Alerts;
use App\Services\Cron;
use App\Services\JobContext;
use App\Services\Jobs;
use App\Services\Settings;
use App\Support\Clock;

/**
 * Scheduled jobs (locks, bounded time, run history) and business alerts (one live alert per problem).
 */

/** The connection settings, read when this file is loaded (before any test can change the environment). */
$GLOBALS['ops_db'] = [
    'host' => (string) Env::get('DB_HOST', 'localhost'), 'port' => (int) Env::get('DB_PORT', 3306), 'name' => (string) Env::get('DB_NAME', ''),
    'user' => (string) Env::get('DB_USER', ''), 'pass' => (string) Env::get('DB_PASS', ''),
];

/** A second database session, to play "another cron process". */
function ops_second_connection(): PDO
{
    $c = $GLOBALS['ops_db'];
    return new PDO('mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** Run $fn with stub handlers for the given jobs and ALWAYS put the real ones back (a failing test must not leak stubs into the next). */
function ops_with_stubs(array $handlers, callable $fn): void
{
    foreach ($handlers as $job => $h) {
        Jobs::register($job, $h);
    }
    try {
        $fn();
    } finally {
        foreach (array_keys($handlers) as $job) {
            Jobs::unregister($job);
        }
    }
}

function ops_admin(): array
{
    return ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];
}

// ═════════════════════════════ scheduled jobs ═════════════════════════════

T::test('jobs: a run is recorded (start, finish, duration, result) and returns what the job returned', function () {
    T::freshDb();
    Jobs::register('t-ok', static fn(JobContext $c): array => ['processed' => 3]);
    $r = Jobs::run('t-ok', ['source' => 'admin']);
    T::same(['t-ok', 'ok', ['processed' => 3]], [$r['job'], $r['status'], $r['result']]);
    $row = Db::one("SELECT * FROM job_runs WHERE job = 't-ok'");
    T::same(['ok', 'admin', 0, null], [$row['status'], $row['trigger_source'], (int) $row['dry_run'], $row['error_category']]);
    T::true($row['finished_at'] !== null && $row['duration_ms'] !== null, 'finished and timed');
    T::same(['processed' => 3], json_decode((string) $row['result_json'], true));
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM job_runs WHERE job = 't-ok' AND status = 'running'"), 'nothing is left "running"');
    Jobs::unregister('t-ok');
});

T::test('jobs: a failing job ends as a FAILED row with a category and a clean message — the run itself never throws', function () {
    T::freshDb();
    Jobs::register('t-boom', static function (JobContext $c): array {
        throw new RuntimeException('cURL error 7: connection refused to /var/www/private/secret-config.php with key KEYabcdefghijklmnopqrstuvwxyz0123456789');
    });
    $r = Jobs::run('t-boom');
    T::same('failed', $r['status']);
    T::same('network', $r['error_category']);
    $row = Db::one("SELECT * FROM job_runs WHERE job = 't-boom'");
    T::same(['failed', 'network'], [$row['status'], $row['error_category']]);
    T::false(str_contains((string) $row['error_message'], 'KEYabcdefghijklmnopqrstuvwxyz'), 'a key never reaches the history');
    T::false(str_contains((string) $row['error_message'], '/var/www/private'), 'nor a path');
    T::true($row['finished_at'] !== null);
    // the lock was released: the same job can run again
    Jobs::register('t-boom', static fn(JobContext $c): array => ['fine' => true]);
    T::same('ok', Jobs::run('t-boom')['status']);
    Jobs::unregister('t-boom');
});

T::test('jobs: a database failure is stored as a category without SQL text', function () {
    T::freshDb();
    Jobs::register('t-db', static function (JobContext $c): array {
        Db::exec('SELECT * FROM this_table_does_not_exist');
        return [];
    });
    $r = Jobs::run('t-db');
    T::same(['failed', 'database'], [$r['status'], $r['error_category']]);
    T::false(str_contains((string) $r['error'], 'this_table_does_not_exist') || str_contains((string) $r['error'], 'SQLSTATE'), (string) $r['error']);
    Jobs::unregister('t-db');
});

T::test('jobs: two processes can never run the same job at once (per-job advisory lock); another job is not blocked', function () {
    T::freshDb();
    $calls = 0;
    ops_with_stubs(['t-lock' => static function (JobContext $c) use (&$calls): array { $calls++; return []; }, 't-other' => static fn(JobContext $c): array => ['ran' => true]], function () use (&$calls) {
        $other = ops_second_connection();                                       // "another cron process" holds the lock
        T::same(1, (int) $other->query("SELECT GET_LOCK('smb:job:t-lock', 0)")->fetchColumn());
        try {
            $r = Jobs::run('t-lock');
            T::same('skipped', $r['status']);
            T::same(0, $calls, 'the handler did not run');
            T::same(0, (int) Db::val("SELECT COUNT(*) FROM job_runs WHERE job = 't-lock'"), 'a skipped run leaves no row behind');
            T::same('ok', Jobs::run('t-other')['status'], 'a different job is not blocked');
        } finally {
            $other->query("SELECT RELEASE_LOCK('smb:job:t-lock')");
        }
        T::same('ok', Jobs::run('t-lock')['status'], 'once the other process is done the job runs');
        T::same(1, $calls);
    });
});

T::test('jobs: the time budget is bounded — a job that outlives it is recorded as "timeout", not "ok"', function () {
    T::freshDb();
    Jobs::register('t-slow', static function (JobContext $c): array {
        $n = 0;
        while (!$c->expired()) {      // the cooperative loop every real job uses
            usleep(100_000);
            $n++;
        }
        return ['batches' => $n];
    });
    $t0 = microtime(true);
    $r = Jobs::run('t-slow', ['max_seconds' => 1]);
    T::true(microtime(true) - $t0 < 3, 'it stopped at its budget');
    T::same('timeout', $r['status']);
    $row = Db::one("SELECT status, error_category FROM job_runs WHERE job = 't-slow'");
    T::same(['timeout', 'timeout'], [$row['status'], $row['error_category']]);
    Jobs::unregister('t-slow');
});

T::test('jobs: a dry run is passed to the job and recorded as a dry run (it never counts as the last success)', function () {
    T::freshDb();
    $seen = null;
    Jobs::register('t-dry', static function (JobContext $c) use (&$seen): array { $seen = $c->dryRun; return ['would_change' => 2]; });
    Jobs::run('t-dry', ['dry_run' => true]);
    T::same(true, $seen);
    T::same(1, (int) Db::val("SELECT dry_run FROM job_runs WHERE job = 't-dry'"));
    Jobs::run('t-dry');
    T::same(false, $seen);
    Jobs::unregister('t-dry');
});

T::test('jobs: a run that died with its process is closed as "abandoned" by the next run (the history stays truthful)', function () {
    T::freshDb();
    Db::insert('job_runs', ['job' => 't-ghost', 'trigger_source' => 'cron', 'status' => 'running', 'started_at' => Clock::sql(Clock::now() - 7200)]);
    Db::insert('job_runs', ['job' => 't-ghost', 'trigger_source' => 'cron', 'status' => 'running', 'started_at' => Clock::sql(Clock::now() - 5)]);   // a live one
    Jobs::register('t-ghost', static fn(JobContext $c): array => []);
    Jobs::run('t-ghost', ['max_seconds' => 30]);
    $rows = Db::all("SELECT status FROM job_runs WHERE job = 't-ghost' ORDER BY id");
    T::same(['abandoned', 'running', 'ok'], array_column($rows, 'status'), 'only the one that could not still be alive is closed');
    Jobs::unregister('t-ghost');
});

T::test('jobs: an unknown job is refused', function () {
    T::true(Jobs::exists('payments') && Jobs::exists('recovery') && Jobs::exists('cleanup') && Jobs::exists('analytics'));
    T::false(Jobs::exists('rm-rf'));
    T::throws(InvalidArgumentException::class, fn() => Jobs::run('rm-rf'));
});

T::test('jobs: runDue runs a job only when its interval has passed; the overview says what state each job is in', function () {
    T::freshDb();
    Clock::setFixed(strtotime('2027-01-15 08:00:00 UTC'));                    // 13:30 IST: after the daily report job's start time, whenever the suite really runs
    $all = ['payments', 'recovery', 'analytics', 'watchdog', 'report', 'cleanup'];
    $GLOBALS['ops_ran'] = [];
    $stub = static function (string $j): callable { return static function (JobContext $c) use ($j): array { $GLOBALS['ops_ran'][] = $j; return []; }; };
    ops_with_stubs(array_combine($all, array_map($stub, $all)), function () use ($all) {
        $over = Jobs::overview();
        T::same($all, array_column($over, 'job'));
        T::same(array_fill(0, 6, 'never_run'), array_column($over, 'state'));

        T::same($all, array_keys(Jobs::runDue()), 'nothing has run yet → everything is due');
        T::same([], Jobs::runDue(), 'just ran → nothing is due');
        T::same(array_fill(0, 6, 'ok'), array_column(Jobs::overview(), 'state'));

        // time passes: payments last started 10 minutes ago (interval 5 min) → due again; cleanup (hourly) is not
        Db::exec("UPDATE job_runs SET started_at = ? WHERE job = 'payments'", [Clock::sql(Clock::now() - 600)]);
        $GLOBALS['ops_ran'] = [];
        T::same(['payments'], array_keys(Jobs::runDue()));
        T::same(['payments'], $GLOBALS['ops_ran']);

        // failing → "failing"; a long silence → "overdue"
        Jobs::register('cleanup', static function (JobContext $c): array { throw new RuntimeException('boom'); });
        Db::exec("UPDATE job_runs SET started_at = ? WHERE job = 'cleanup'", [Clock::sql(Clock::now() - 7200)]);
        Jobs::run('cleanup');
        T::same('failing', array_column(Jobs::overview(), 'state', 'job')['cleanup']);
        Db::exec("UPDATE job_runs SET started_at = ?, finished_at = ? WHERE job = 'analytics'", [Clock::sql(Clock::now() - 7200), Clock::sql(Clock::now() - 7200)]);
        T::same('overdue', array_column(Jobs::overview(), 'state', 'job')['analytics']);
        $row = array_column(Jobs::overview(), null, 'job')['cleanup'];
        T::same(['failed', 'logic'], [$row['last_run']['status'], $row['last_run']['error_category']]);
        T::true($row['failures_24h'] >= 1);
    });
    Clock::setFixed(null);
});

T::test('cron: the one-line entry point runs the quick jobs every time and reports the skip when it is already running', function () {
    T::freshDb();
    $stub = static fn(string $j): callable => static fn(JobContext $c): array => ['job' => $j];
    ops_with_stubs(['payments' => $stub('payments'), 'recovery' => $stub('recovery'), 'analytics' => $stub('analytics'), 'watchdog' => $stub('watchdog'), 'report' => $stub('report'), 'cleanup' => $stub('cleanup')], function () {
        $out = Cron::run();
        foreach (['payments', 'recovery', 'analytics', 'watchdog', 'cleanup'] as $j) {
            T::true(isset($out[$j]), "$j is in the output");
        }
        T::true(isset($out['took_ms']));
        T::true((int) Settings::get('cron.last_run', 0) > 0, 'the legacy "last run" marker is kept');
        $other = ops_second_connection();
        T::same(1, (int) $other->query("SELECT GET_LOCK('smb:cron', 0)")->fetchColumn());
        try {
            T::same(['skipped' => 'another run is in progress'], Cron::run());
        } finally {
            $other->query("SELECT RELEASE_LOCK('smb:cron')");
        }
    });
});

T::test('cleanup: retention deletes only what has expired — old job history, conversations past their retention, expired assignments — never live rows', function () {
    T::freshDb();
    $now = Clock::now();
    Db::insert('job_runs', ['job' => 'payments', 'status' => 'ok', 'started_at' => Clock::sql($now - 100 * 86400)]);
    Db::insert('job_runs', ['job' => 'payments', 'status' => 'ok', 'started_at' => Clock::sql($now - 3600)]);
    $old = Db::insert('support_conversations', ['public_key' => str_repeat('a', 32), 'created_at' => Clock::sql($now - 40 * 86400), 'last_message_at' => Clock::sql($now - 40 * 86400), 'expires_at' => Clock::sql($now - 10 * 86400)]);
    Db::insert('support_messages', ['conversation_id' => $old, 'role' => 'customer', 'body' => 'hello', 'created_at' => Clock::sql($now - 40 * 86400)]);
    Db::insert('support_conversations', ['public_key' => str_repeat('b', 32), 'created_at' => Clock::sql($now), 'last_message_at' => Clock::sql($now), 'expires_at' => Clock::sql($now + 86400)]);
    $r = Jobs::run('cleanup');
    T::same('ok', $r['status'], json_encode($r));
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM job_runs WHERE job = 'payments' AND started_at < ?", [Clock::sql($now - 90 * 86400)]), 'the 100-day-old run is gone');
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM job_runs WHERE job = 'payments' AND status = 'ok' AND started_at > ?", [Clock::sql($now - 7200)]), 'the recent one is kept');
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM support_conversations'), 'only the live conversation is left');
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM support_messages'), 'its messages went with the expired conversation');
});

// ═════════════════════════════ business alerts ═════════════════════════════

T::test('alerts: the same problem seen again updates ONE alert (occurrences, last detected) instead of creating another', function () {
    T::freshDb();
    $a = Alerts::raise('payment_failure_rate', 'warning', 'Payment failures are high', '8 of 20 payments failed in the last hour (40 %).');
    T::true($a['created'] && $a['occurrences'] === 1 && $a['id'] > 0);
    $b = Alerts::raise('payment_failure_rate', 'warning', 'Payment failures are high', '9 of 21 payments failed in the last hour (43 %).');
    T::same([false, 2, $a['id']], [$b['created'], $b['occurrences'], $b['id']]);
    $c = Alerts::raise('payment_failure_rate', 'warning', 'Payment failures are high', '10 of 22 payments failed.');
    T::same(3, $c['occurrences']);
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM business_alerts'), 'still one row');
    $row = Db::one('SELECT * FROM business_alerts');
    T::same('open', $row['status']);
    T::contains('10 of 22', $row['description'], 'the description shows the latest numbers');
    T::true($row['first_detected_at'] <= $row['last_detected_at']);
    // a different problem (different key) is a different alert
    Alerts::raise('stuck_orders', 'warning', 'Orders are stuck', '2 orders have been in PROCESSING for over 10 minutes.');
    Alerts::raise('stuck_orders', 'warning', 'Orders are stuck', '2 orders …', ['dedupe_key' => 'stuck_orders:other']);
    T::same(3, (int) Db::val('SELECT COUNT(*) FROM business_alerts'));
});

T::test('alerts: a problem that gets worse escalates the severity and never quietly drops it', function () {
    T::freshDb();
    Alerts::raise('sales_drop', 'info', 'Sales are lower', 'Down 30 %.');
    Alerts::raise('sales_drop', 'critical', 'Sales dropped', 'Down 80 %.');
    T::same('critical', Db::val('SELECT severity FROM business_alerts'));
    Alerts::raise('sales_drop', 'warning', 'Sales are lower', 'Down 40 %.');
    T::same('critical', Db::val('SELECT severity FROM business_alerts'), 'a milder re-detection does not lower an open alert');
    T::same(3, (int) Db::val('SELECT occurrences FROM business_alerts'));
});

T::test('alerts: acknowledging records who saw it and keeps the alert live (still counted, no second alert); resolving closes it', function () {
    T::freshDb();
    $a = Alerts::raise('invalid_webhooks', 'warning', 'Webhooks with a bad signature', '12 in the last hour.');
    Alerts::acknowledge($a['id'], ops_admin());
    $row = Db::one('SELECT * FROM business_alerts WHERE id = ?', [$a['id']]);
    T::same(['acknowledged', 1], [$row['status'], (int) $row['acknowledged_by']]);
    T::true($row['acknowledged_at'] !== null);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'alert.acknowledged'"));
    $again = Alerts::raise('invalid_webhooks', 'warning', 'Webhooks with a bad signature', '15 in the last hour.');
    T::same([false, 2, $a['id']], [$again['created'], $again['occurrences'], $again['id']], 'detected again: the same, acknowledged alert counts it');
    T::same('acknowledged', Db::val('SELECT status FROM business_alerts WHERE id = ?', [$a['id']]), 'it stays acknowledged');
    T::throws(HttpException::class, fn() => Alerts::acknowledge($a['id'], ops_admin()), 409);

    Alerts::resolve($a['id'], ops_admin());
    $row = Db::one('SELECT * FROM business_alerts WHERE id = ?', [$a['id']]);
    T::same(['resolved', 'admin', null], [$row['status'], $row['resolution'], $row['active_key']]);
    T::true($row['resolved_at'] !== null);
    T::throws(HttpException::class, fn() => Alerts::resolve($a['id'], ops_admin()), 409);
    T::throws(HttpException::class, fn() => Alerts::acknowledge(999999, ops_admin()), 404);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'alert.resolved'"));
});

T::test('alerts: a recurrence after resolution opens a NEW alert; a check that sees the problem gone resolves it automatically', function () {
    T::freshDb();
    $a = Alerts::raise('missing_costs', 'warning', 'Services without a cost', '3 services have no cost.');
    T::true(Alerts::clear('missing_costs'), 'the problem went away → auto-resolved');
    T::same(['resolved', 'auto'], [Db::val('SELECT status FROM business_alerts'), Db::val('SELECT resolution FROM business_alerts')]);
    T::false(Alerts::clear('missing_costs'), 'nothing live to clear any more');
    $b = Alerts::raise('missing_costs', 'warning', 'Services without a cost', '2 services have no cost.');
    T::true($b['created'] && $b['id'] !== $a['id'], 'it came back: a fresh alert, the old one stays as history');
    T::same(2, (int) Db::val('SELECT COUNT(*) FROM business_alerts'));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM business_alerts WHERE status = 'open'"));
});

T::test('alerts: only one live alert per problem can exist — enforced by the database, so two racing checks cannot both create it', function () {
    T::freshDb();
    Alerts::raise('stuck_orders', 'warning', 't', 'd');
    $threw = false;
    try {
        Db::insert('business_alerts', ['alert_type' => 'stuck_orders', 'severity' => 'warning', 'title' => 't', 'description' => 'd', 'dedupe_key' => 'stuck_orders', 'active_key' => 'stuck_orders',
            'status' => 'open', 'occurrences' => 1, 'first_detected_at' => Clock::sql(), 'last_detected_at' => Clock::sql()]);
    } catch (PDOException) {
        $threw = true;
    }
    T::true($threw, 'a second live row with the same key is refused by the unique index');
    Alerts::resolve((int) Db::val('SELECT id FROM business_alerts'), ops_admin());
    Alerts::raise('stuck_orders', 'warning', 't', 'd');
    Alerts::resolve((int) Db::val("SELECT id FROM business_alerts WHERE status = 'open'"), ops_admin());
    T::same(2, (int) Db::val('SELECT COUNT(*) FROM business_alerts'), 'many RESOLVED rows with the same key are fine (their active key is NULL)');
});

T::test('alerts: descriptions are cleaned — no paths, no long tokens — and the counts and list report only live alerts by default', function () {
    T::freshDb();
    Alerts::raise('app_errors', 'warning', 'Errors in the log', 'Failed in ' . SMB_ROOT . '/app/Services/Jobs.php with token KEYabcdefghijklmnopqrstuvwxyz0123456789 (4 errors)');
    $d = (string) Db::val('SELECT description FROM business_alerts');
    T::false(str_contains($d, SMB_ROOT) || str_contains($d, 'KEYabcdefghijklmnopqrstuvwxyz'), $d);
    T::contains('4 errors', $d);
    Alerts::raise('stuck_orders', 'critical', 'Orders stuck', '5 orders');
    Alerts::raise('info_thing', 'info', 'FYI', 'nothing urgent');
    $second = Alerts::raise('to_close', 'warning', 'Closing', 'x');
    Alerts::resolve($second['id'], ops_admin());
    T::same(['critical' => 1, 'warning' => 1, 'info' => 1, 'total' => 3, 'unacknowledged' => 3, 'unacknowledged_critical' => 1], Alerts::counts());
    $live = Alerts::list();
    T::same(3, $live['total']);
    T::same(['critical', 'warning', 'info'], array_column($live['rows'], 'severity'), 'most severe first');
    T::same(1, Alerts::list(['status' => 'resolved'])['total']);
    T::same(4, Alerts::list(['status' => 'all'])['total']);
    T::same(1, Alerts::list(['severity' => 'critical'])['total']);
    T::same(1, Alerts::list(['type' => 'app_errors'])['total']);
    // acknowledging keeps an alert live but takes it off the count of critical alerts nobody has seen (the red menu badge)
    $critical = (int) Db::val("SELECT id FROM business_alerts WHERE severity = 'critical' AND status = 'open'");
    Alerts::acknowledge($critical, ops_admin());
    T::same([1, 2, 0], [Alerts::counts()['critical'], Alerts::counts()['unacknowledged'], Alerts::counts()['unacknowledged_critical']]);
});

T::test('alerts: e-mail is off by default; when on it respects the minimum severity and the cooldown, and contains no secrets', function () {
    T::freshDb();
    $sent = [];
    Alerts::$mailer = static function (string $to, string $subject, string $body, string $headers) use (&$sent): bool { $sent[] = compact('to', 'subject', 'body', 'headers'); return true; };
    try {
        $a = Alerts::raise('stuck_orders', 'critical', 'Orders stuck', '5 orders have been stuck.');
        T::false($a['notified']);
        T::same(0, count($sent), 'nothing is sent unless an admin turned e-mail on');

        Settings::setMany(['alerts.email_enabled' => true, 'alerts.email_to' => 'owner@example.test, not-an-address, boss@example.test', 'alerts.email_min_severity' => 'critical', 'alerts.email_cooldown_min' => 60]);
        $b = Alerts::raise('db_errors', 'warning', 'Warnings', 'A warning-level problem.');
        T::false($b['notified'], 'below the minimum severity');
        $c = Alerts::raise('stuck_orders', 'critical', 'Orders stuck', '6 orders have been stuck.');
        T::true($c['notified'], 'critical and never notified → one message');
        T::same(1, count($sent));
        T::same('owner@example.test,boss@example.test', $sent[0]['to'], 'an invalid address is dropped');
        T::contains('Orders stuck', $sent[0]['subject']);
        T::contains('/admin/alerts', $sent[0]['body']);
        T::false((bool) preg_match('/sk_live|secret|password|api[_-]?key/i', $sent[0]['body'] . $sent[0]['headers']), 'no credentials in a notification');
        T::false((bool) preg_match('/[\r\n]/', $sent[0]['to']), 'no header injection through an address');
        $d = Alerts::raise('stuck_orders', 'critical', 'Orders stuck', '7 orders have been stuck.');
        T::false($d['notified'], 'within the cooldown: no second message for the same alert');
        T::same(1, count($sent));
        Db::exec('UPDATE business_alerts SET last_notified_at = ? WHERE id = ?', [Clock::sql(Clock::now() - 7200), $c['id']]);
        T::true(Alerts::raise('stuck_orders', 'critical', 'Orders stuck', '8 orders have been stuck.')['notified'], 'after the cooldown it may notify again');
        T::same(2, count($sent));
    } finally {
        Alerts::$mailer = null;
        Settings::setMany(['alerts.email_enabled' => false]);
    }
});

// ═════════════════════════════ authorisation (the server decides) ═════════════════════════════

T::test('authorize: a controller refuses an action the signed-in role may not do (403), and lets the owner through', function () {
    $ctl = new class extends \App\Controllers\Admin\BaseController {
        public function check(\App\Core\Request $req, string $permission): array { return $this->authorize($req, $permission); }
    };
    $as = static function (string $role): \App\Core\Request {
        $req = \App\Core\Request::fake('POST', '/admin/api/x');
        $req->attributes['admin'] = ['id' => 5, 'username' => $role . '-user', 'email' => 'u@x.test', 'role' => $role, 'must_change_password' => false];
        return $req;
    };
    T::same('owner-user', $ctl->check($as('owner'), 'payments.override')['username']);
    $e = T::throws(HttpException::class, fn() => $ctl->check($as('manager'), 'payments.override'), 403);
    T::same('forbidden', $e->errorCode);
    T::contains('manager', $e->getMessage(), 'the message names the role');
    T::throws(HttpException::class, fn() => $ctl->check($as('analyst'), 'finance.adjust'), 403);
    T::same('manager-user', $ctl->check($as('manager'), 'finance.adjust')['username']);
    T::throws(HttpException::class, fn() => $ctl->check(\App\Core\Request::fake('POST', '/admin/api/x'), 'finance.view'), 401);   // not signed in
    T::throws(HttpException::class, fn() => $ctl->check($as('owner'), 'made.up'), 403);                                              // an unknown permission is never granted
});
