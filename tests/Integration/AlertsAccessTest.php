<?php
declare(strict_types=1);

use App\Controllers\Admin\AlertsController;
use App\Controllers\Admin\ReportsController;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Alerts;
use App\Services\DailyReport;
use App\Services\Settings;
use App\Services\Watchdog;
use App\Support\Tz;

/**
 * Who may see and change alerts and reports. Reading alerts needs alerts.view (every role), reading reports reports.view (owner, manager, analyst —
 * the roles that may see profit); acknowledging, resolving, changing a threshold or the e-mail needs alerts.manage and (re)generating a report
 * reports.generate (owner, manager). A refused call changes nothing and writes no audit row.
 */

function la_req(string $method, string $path, string $role, array $body = [], array $query = [], array $headers = []): Request
{
    $req = Request::fake($method, $path, ['Content-Type' => 'application/json'] + $headers, $body ? json_encode($body) : '', [], '203.0.113.9', $query);
    $req->attributes['admin'] = ['id' => 1, 'username' => $role . '-user', 'email' => 'u@x.test', 'role' => $role, 'must_change_password' => false];
    return $req;
}

/** @return array<string,mixed> */
function la_json(Response $res): array
{
    return json_decode((string) $res->body, true) ?: [];
}

T::test('alerts access: every role reads, only owners and managers change anything — and a refused call changes nothing', function () {
    wd_reset();
    $a = Alerts::raise('stuck_orders', 'critical', 'Paid orders are stuck', '2 orders.', ['dedupe_key' => 'wd:stuck_orders']);
    $b = Alerts::raise('sales_drop', 'warning', 'Verified sales have dropped', 'down', ['dedupe_key' => 'wd:sales_drop']);
    Alerts::saveMailSettings(['enabled' => true, 'to' => 'owner@example.test', 'from' => 'alerts@example.test'], ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner']);
    $ctl = new AlertsController();

    $reads = [
        'list' => static fn(string $r): Response => $ctl->list(la_req('GET', '/admin/api/alerts', $r), []),
        'checks' => static fn(string $r): Response => $ctl->checks(la_req('GET', '/admin/api/alerts/checks', $r), []),
        'settings' => static fn(string $r): Response => $ctl->settings(la_req('GET', '/admin/api/alerts/settings', $r), []),
    ];
    foreach ($reads as $name => $call) {
        foreach (['owner', 'manager', 'analyst', 'support'] as $role) {
            T::true(la_json($call($role))['ok'], "$role reads $name");
        }
    }
    $list = la_json($reads['list']('support'));
    T::same([2, 1, false, false], [$list['total'], $list['counts']['critical'], $list['can']['manage'], $list['can']['run']]);
    T::same('Paid orders are stuck', $list['rows'][0]['title'], 'critical first');
    T::true(isset($list['types']['payment_failures'], $list['types']['payment_flag']), 'the page can name every kind of alert');
    T::same([true, true], [la_json($reads['list']('manager'))['can']['manage'], la_json($reads['list']('manager'))['can']['run']]);

    // the recipients’ addresses are for those who may change them
    $mail = la_json($reads['settings']('analyst'))['mail'];
    T::same([true, 1], [$mail['enabled'], $mail['recipients']]);
    T::false(isset($mail['to']) || isset($mail['from']), 'an analyst sees that e-mail is on, not whom it goes to');
    $mail = la_json($reads['settings']('manager'))['mail'];
    T::same(['owner@example.test', 'alerts@example.test'], [$mail['to'], $mail['from']]);
    T::same(12, count(la_json($reads['checks']('analyst'))['watchdog']['checks']));

    $writes = [
        'acknowledge' => static fn(string $r): Response => $ctl->acknowledge(la_req('POST', "/admin/api/alerts/{$GLOBALS['la_a']}/acknowledge", $r), ['id' => (string) $GLOBALS['la_a']]),
        'resolve' => static fn(string $r): Response => $ctl->resolve(la_req('POST', "/admin/api/alerts/{$GLOBALS['la_b']}/resolve", $r), ['id' => (string) $GLOBALS['la_b']]),
        'saveSettings' => static fn(string $r): Response => $ctl->saveSettings(la_req('PUT', '/admin/api/alerts/settings', $r, ['thresholds' => ['pay_fail_pct' => 25]]), []),
        'saveMail' => static fn(string $r): Response => $ctl->saveMail(la_req('PUT', '/admin/api/alerts/mail', $r, ['enabled' => false, 'to' => '']), []),
        'testMail' => static fn(string $r): Response => $ctl->testMail(la_req('POST', '/admin/api/alerts/mail/test', $r), []),
    ];
    $GLOBALS['la_a'] = $a['id'];
    $GLOBALS['la_b'] = $b['id'];
    $sent = 0;
    \App\Services\Alerts::$mailer = static function (string $to, string $subject, string $body, string $headers) use (&$sent): bool {
        $sent++;
        return true;
    };
    $snapshot = static fn(): array => [
        Db::all('SELECT id, status FROM business_alerts ORDER BY id'), Watchdog::thresholds()['pay_fail_pct'], Alerts::mailSettings()['enabled'],
        (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'alert%' OR action LIKE 'watchdog%'"), $sent,
    ];
    $before = $snapshot();
    foreach (['analyst', 'support'] as $role) {
        foreach ($writes as $name => $call) {
            $e = T::throws(HttpException::class, fn() => $call($role), 403);
            T::same('forbidden', $e->errorCode, "$role is refused $name");
        }
    }
    T::same($before, $snapshot(), 'the refused calls changed nothing, sent nothing and wrote no audit row');

    foreach (['owner', 'manager'] as $role) {
        T::same(true, la_json($writes['testMail']($role))['result']['sent'], "$role sends the test message");
    }
    T::same(2, $sent);

    $key = ['Idempotency-Key' => 'alert-ack-1'];
    $ack = static fn(array $h = []): Response => $ctl->acknowledge(la_req('POST', "/admin/api/alerts/{$GLOBALS['la_a']}/acknowledge", 'manager', [], [], $h), ['id' => (string) $GLOBALS['la_a']]);
    T::same(1, la_json($ack($key))['counts']['unacknowledged'] ?? null, 'acknowledged: one alert is still unseen');
    $again = $ack($key);
    T::same(['true'], array_values(array_filter([$again->headers['X-Idempotent-Replay'] ?? null])), 'a double click is a replay, not a conflict');
    T::throws(HttpException::class, fn() => $ack(), 409);
    T::same(['acknowledged', 'open'], array_column(Db::all('SELECT status FROM business_alerts ORDER BY id'), 'status'));

    T::same(1, la_json($writes['resolve']('owner'))['counts']['total']);
    T::throws(HttpException::class, fn() => $ctl->resolve(la_req('POST', '/admin/api/alerts/999999/resolve', 'owner'), ['id' => '999999']), 404);

    $s = la_json($writes['saveSettings']('manager'));
    T::same(25, array_values(array_filter($s['fields'], static fn(array $f): bool => $f['name'] === 'pay_fail_pct'))[0]['value']);
    $e = T::throws(HttpException::class, fn() => $ctl->saveSettings(la_req('PUT', '/admin/api/alerts/settings', 'owner', ['thresholds' => ['pay_fail_pct' => 1000]]), []), 422);
    T::true(isset($e->extra['fields']['pay_fail_pct']));

    $m = la_json($writes['saveMail']('owner'))['mail'];
    T::same([false, ''], [$m['enabled'], $m['to']]);
    $e = T::throws(HttpException::class, fn() => $ctl->testMail(la_req('POST', '/admin/api/alerts/mail/test', 'owner'), []), 422);
    T::contains('recipient', $e->getMessage());
    foreach (['alert.acknowledged', 'alert.resolved', 'watchdog.settings_changed', 'alerts.mail_settings_changed', 'alerts.test_email'] as $action) {
        T::true((int) Db::val('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]) >= 1, "$action is audited");
    }
});

T::test('alerts access: the test e-mail is rate-limited per admin', function () {
    wd_reset();
    $owner = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];
    Alerts::saveMailSettings(['to' => 'owner@example.test'], $owner);
    Alerts::$mailer = static fn(string $to, string $subject, string $body, string $headers): bool => true;
    $ctl = new AlertsController();
    for ($i = 0; $i < 5; $i++) {
        T::true(la_json($ctl->testMail(la_req('POST', '/admin/api/alerts/mail/test', 'owner'), []))['ok']);
    }
    $e = T::throws(HttpException::class, fn() => $ctl->testMail(la_req('POST', '/admin/api/alerts/mail/test', 'owner'), []), 429);
    T::contains('Too many', $e->getMessage());
});

T::test('reports access: owners, managers and analysts read; support is refused; only owners and managers generate — and a refused call creates nothing', function () {
    dr_reset();
    $day = dr_yesterday();
    an_order('MANUAL', 10000, 0, $day);
    DailyReport::generate($day);
    $ctl = new ReportsController();

    $reads = [
        'list' => static fn(string $r): Response => $ctl->list(la_req('GET', '/admin/api/reports', $r), []),
        'show' => static fn(string $r): Response => $ctl->show(la_req('GET', "/admin/api/reports/{$GLOBALS['la_day']}", $r), ['date' => $GLOBALS['la_day']]),
    ];
    $GLOBALS['la_day'] = $day;
    foreach ($reads as $name => $call) {
        foreach (['owner', 'manager', 'analyst'] as $role) {
            T::true(la_json($call($role))['ok'], "$role reads $name");
        }
        $e = T::throws(HttpException::class, fn() => $call('support'), 403);
        T::same('forbidden', $e->errorCode, "support is refused $name");
    }
    $list = la_json($reads['list']('analyst'));
    T::same([1, false, $day, 'Asia/Kolkata (UTC+05:30)'], [$list['total'], $list['can']['generate'], $list['latest_day'], $list['timezone']]);
    T::same(true, la_json($reads['list']('manager'))['can']['generate']);
    $shown = la_json($reads['show']('analyst'))['report'];
    T::same([$day, 10000], [$shown['date'], $shown['snapshot']['metrics']['collected']['value']]);
    T::throws(HttpException::class, fn() => $ctl->show(la_req('GET', '/admin/api/reports/2020-01-01', 'owner'), ['date' => '2020-01-01']), 404);

    // generating
    $other = Tz::addDays($day, -1);
    an_order('MANUAL', 20000, 0, $other);
    $gen = static fn(string $role, string $d, array $body = [], array $h = []): Response => $ctl->generate(la_req('POST', "/admin/api/reports/{$d}/generate", $role, $body, [], $h), ['date' => $d]);
    $audit = static fn(): int => (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'report.%'");
    foreach (['analyst', 'support'] as $role) {
        $e = T::throws(HttpException::class, fn() => $gen($role, $other), 403);
        T::same('forbidden', $e->errorCode, "$role is refused generate");
    }
    T::same([1, 0], [dr_count(), $audit()], 'nothing was created, nothing audited');

    $r = la_json($gen('manager', $other));
    T::same([true, 'Report created.', 2], [$r['result']['created'], $r['message'], dr_count()]);
    $r = la_json($gen('manager', $other));
    T::same([false, 2], [$r['result']['created'], dr_count()]);
    T::contains('already has a report', $r['message']);
    an_order('MANUAL', 5000, 0, $other);
    $r = la_json($gen('owner', $other, ['force' => true], ['Idempotency-Key' => 'regen-key-1']));
    T::same(true, $r['result']['regenerated']);
    T::contains('figures changed', $r['message']);
    T::same(25000, $r['report']['snapshot']['metrics']['collected']['value']);
    $replay = $gen('owner', $other, ['force' => true], ['Idempotency-Key' => 'regen-key-1']);
    T::same(['true'], array_values(array_filter([$replay->headers['X-Idempotent-Replay'] ?? null])));
    T::same(1, (int) dr_row($other)['regenerated_count'], 'the replayed click did not regenerate again');

    foreach ([Tz::today(), '2027-13-40', 'abc'] as $bad) {
        $e = T::throws(HttpException::class, fn() => $gen('owner', $bad), 422);
        T::true(isset($e->extra['fields']['date']), $bad);
    }
});
