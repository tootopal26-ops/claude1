<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Services\Alerts;
use App\Services\JobContext;
use App\Services\Jobs;
use App\Services\Settings;
use App\Services\Watchdog;
use App\Smm\SmmResponse;
use App\Support\Clock;
use App\Support\Tz;

/**
 * The Business Watchdog: every check reads records the shop already keeps, compares them with a threshold, and says one of
 * ok / alert / insufficient / unavailable — never "healthy" for something it could not measure. One live alert per problem.
 * Time stands still at 2027-01-15 08:00 UTC (13:30 in Asia/Kolkata), the AN_DAY0 of the analytics tests.
 */

function wd_reset(): void
{
    an_reset();
    Alerts::$mailer = null;
    Watchdog::$balanceFetcher = null;
    static $quietLog = null;
    if ($quietLog === null) {                                // an empty log folder: the real application log must not decide a test's outcome
        $quietLog = sys_get_temp_dir() . '/smb-wd-quiet-log-' . bin2hex(random_bytes(4));
        mkdir($quietLog, 0775, true);
    }
    Watchdog::$logDir = $quietLog;
}

/** Run the checks the way the job does. @return array<string,mixed> */
function wd_run(bool $dry = false): array
{
    return Watchdog::run(new JobContext(microtime(true) + 60, $dry));
}

function wd_alerts(string $type): array
{
    return Db::all('SELECT * FROM business_alerts WHERE alert_type = ? ORDER BY id', [$type]);
}

/** A payment (and its order) created $agoSeconds ago, in the given payment status. */
function wd_payment(string $status, int $agoSeconds = 600): int
{
    static $n = 0;
    $n++;
    $svc = Db::one("SELECT s.*, p.slug FROM services s JOIN platforms p ON p.id = s.platform_id WHERE s.code = 'ig-followers-50'");
    $at = Clock::now() - $agoSeconds;
    $paid = $status === 'success';
    $orderId = Db::insert('orders', [
        'public_id' => 'SMB-W' . str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'access_token' => bin2hex(random_bytes(16)), 'service_id' => $svc['id'], 'platform_id' => $svc['platform_id'],
        'platform_slug' => $svc['slug'], 'service_code' => $svc['code'], 'service_title' => $svc['title'], 'service_type' => $svc['type'], 'mode' => 'MANUAL', 'quantity' => 50,
        'amount_paise' => 5000, 'target' => '@x', 'target_url' => 'https://www.instagram.com/x/', 'status' => $paid ? 'MANUAL_PENDING' : 'PENDING_PAYMENT',
        'payment_status' => $paid ? 'success' : 'pending', 'created_day' => Tz::dayOf($at), 'paid_day' => $paid ? Tz::dayOf($at) : null, 'paid_at' => $paid ? Clock::sql($at + 20) : null,
        'created_at' => Clock::sql($at), 'updated_at' => Clock::sql($at),
    ]);
    return Db::insert('payments', [
        'ref' => bin2hex(random_bytes(16)), 'order_id' => $orderId, 'provider' => 'alpha_upi', 'gateway_order_id' => 'WD' . $n . bin2hex(random_bytes(3)), 'amount_paise' => 5000,
        'paid_amount_paise' => $paid ? 5000 : null, 'status' => $status, 'expires_at' => Clock::sql($at + 300), 'paid_at' => $paid ? Clock::sql($at + 20) : null,
        'paid_day' => $paid ? Tz::dayOf($at) : null, 'created_day' => Tz::dayOf($at), 'created_at' => Clock::sql($at), 'updated_at' => Clock::sql($at),
    ]);
}

function wd_webhook(string $status, bool $valid = true, int $attempts = 1, int $agoSeconds = 300): void
{
    static $n = 0;
    $n++;
    Db::insert('webhook_events', [
        'provider' => 'alpha_upi', 'event_id' => 'E' . $n, 'event_type' => 'payment', 'dedupe_key' => $valid ? 'alpha_upi:E' . $n : null, 'signature_valid' => $valid ? 1 : 0,
        'payload_hash' => hash('sha256', 'p' . $n), 'status' => $status, 'attempts' => $attempts, 'received_at' => Clock::sql(Clock::now() - $agoSeconds),
        'last_attempt_at' => $attempts > 1 ? Clock::sql(Clock::now() - 60) : null,
    ]);
}

function wd_smm(int $providerId, bool $ok, ?int $http = 200, string $error = '', int $agoSeconds = 300, string $action = 'add'): void
{
    Db::insert('smm_transactions', [
        'provider_id' => $providerId, 'action' => $action, 'ok' => $ok ? 1 : 0, 'http_status' => $http, 'error' => $error !== '' ? $error : null, 'created_at' => Clock::sql(Clock::now() - $agoSeconds),
    ]);
}

/** Every service has a cost, so the "missing costs" check has nothing to say (the seeded catalogue has none). */
function wd_costs_set(): void
{
    Db::exec('UPDATE services SET smm_cost_paise = 100, fulfil_cost_paise = 0');
    Settings::set('finance.fee_mode', 'none');
}

// ═════════════════════════════ payment failures ═════════════════════════════

T::test('watchdog: a payment-failure breach raises ONE alert, repeats only count up, and it resolves after quiet runs — not on "no data"', function () {
    wd_reset();
    for ($i = 0; $i < 6; $i++) {
        wd_payment('failed');
    }
    for ($i = 0; $i < 4; $i++) {
        wd_payment('success');
    }
    wd_payment('expired');                                   // a customer who walked away is not a failure
    $r = wd_run();
    T::same('alert', $r['verdicts']['payment_failures']);
    $a = wd_alerts('payment_failures');
    T::same(1, count($a));
    T::same(['warning', 'open', 1, 'wd:payment_failures'], [$a[0]['severity'], $a[0]['status'], (int) $a[0]['occurrences'], $a[0]['active_key']]);
    T::contains('6 of 10', $a[0]['description']);
    T::contains('60.0%', $a[0]['description']);
    T::contains('1 more session expired', $a[0]['description']);

    // seen again: the SAME alert, one more occurrence
    Clock::advance(900);
    wd_run();
    T::same([1, 2], [count(wd_alerts('payment_failures')), (int) wd_alerts('payment_failures')[0]['occurrences']]);

    // two hours later nothing is in the window: the check cannot judge, and an alert is NOT closed on that basis
    Clock::advance(7200);
    $r = wd_run();
    T::same('insufficient', $r['verdicts']['payment_failures']);
    T::same('open', wd_alerts('payment_failures')[0]['status'], 'no data is not proof that the problem is over');

    // healthy payments: one quiet run is not enough to resolve, two are
    for ($i = 0; $i < 10; $i++) {
        wd_payment('success', 120);
    }
    $r = wd_run();
    T::same('ok', $r['verdicts']['payment_failures']);
    T::same('open', wd_alerts('payment_failures')[0]['status']);
    wd_run();
    $a = wd_alerts('payment_failures')[0];
    T::same(['resolved', 'auto', null], [$a['status'], $a['resolution'], $a['active_key']]);

    // it happens again: a NEW alert, the resolved one stays as history
    for ($i = 0; $i < 12; $i++) {
        wd_payment('failed', 60);
    }
    wd_run();
    T::same([2, 1], [count(wd_alerts('payment_failures')), (int) Db::val("SELECT COUNT(*) FROM business_alerts WHERE alert_type = 'payment_failures' AND status = 'open'")]);
});

T::test('watchdog: the failure alert is critical at twice the threshold; too few payments is "insufficient", never "ok"', function () {
    wd_reset();
    for ($i = 0; $i < 3; $i++) {
        wd_payment('failed');
    }
    $r = Watchdog::evaluate('payment_failures');
    T::same('insufficient', $r['status']);
    T::contains('at least 5', $r['summary']);
    T::same([], $r['findings']);
    wd_run();
    T::same([], wd_alerts('payment_failures'), 'too few payments raise nothing');

    for ($i = 0; $i < 6; $i++) {
        wd_payment('failed');
    }
    wd_payment('success');                                   // 9 of 10 failed: 90 % ≥ 2 × 40 %
    $r = Watchdog::evaluate('payment_failures');
    T::same(['alert', 'critical'], [$r['status'], $r['findings'][0]['severity']]);
});

// ═════════════════════════════ payment notifications ════════════════════════

T::test('watchdog: rejected, unprocessed and endlessly repeated gateway notifications are reported — below the limits they are not', function () {
    wd_reset();
    for ($i = 0; $i < 9; $i++) {
        wd_webhook('invalid', false);
    }
    T::same('ok', Watchdog::evaluate('webhooks')['status'], '9 rejected is under the limit of 10');
    wd_webhook('invalid', false);
    $r = Watchdog::evaluate('webhooks');
    T::same(['alert', 'warning'], [$r['status'], $r['findings'][0]['severity']]);
    T::contains('10 notifications failed signature verification', $r['findings'][0]['text']);
    T::contains('A rejected notification never marks an order paid', $r['findings'][0]['text']);

    wd_reset();
    for ($i = 0; $i < 3; $i++) {
        wd_webhook('error');
    }
    T::same('warning', Watchdog::evaluate('webhooks')['findings'][0]['severity']);
    for ($i = 0; $i < 3; $i++) {
        wd_webhook('mismatch');
    }
    $r = Watchdog::evaluate('webhooks');
    T::same('critical', $r['findings'][0]['severity'], 'six that could not be processed is twice the limit of three');
    T::contains('3 notifications could not be processed and 3 did not match the order', $r['findings'][0]['text']);

    wd_reset();
    for ($i = 0; $i < 5; $i++) {
        wd_webhook('processed', true, 4);
    }
    $r = Watchdog::evaluate('webhooks');
    T::contains('5 notifications were delivered three or more times', $r['findings'][0]['text']);

    wd_reset();
    T::contains('No payment notifications arrived', Watchdog::evaluate('webhooks')['summary']);
});

// ═════════════════════════════ SMM provider calls ═══════════════════════════

T::test('watchdog: a failing SMM provider is named, split into "could not reach" and "rejected", and a healthy one is left alone', function () {
    wd_reset();
    $a = T::configureSmm();
    $b = Db::insert('smm_providers', ['name' => 'Second panel', 'api_url' => 'https://smm.example.test/api/v2', 'api_key_enc' => 'x', 'is_active' => 1, 'is_default' => 0, 'created_at' => Clock::sql(), 'updated_at' => Clock::sql()]);
    T::same('insufficient', Watchdog::evaluate('provider_errors')['status'], 'no call at all: nothing to judge');

    wd_smm($a, false, null, 'Network error (28): Operation timed out');
    wd_smm($a, false, null, 'Network error (7): Failed to connect');
    wd_smm($a, false, 502, 'The provider reported a server error (HTTP 502).');
    wd_smm($a, false, 200, 'Incorrect link');
    wd_smm($a, false, 200, 'Not enough funds');
    wd_smm($a, true);
    wd_smm($a, false, null, 'a balance test that failed long ago', 300, 'balance');          // not an order call: ignored
    for ($i = 0; $i < 5; $i++) {
        wd_smm($b, true);
    }
    wd_smm($b, false);
    $r = Watchdog::evaluate('provider_errors');
    T::same('alert', $r['status']);
    T::same(1, count($r['findings']), 'only the failing provider');
    $f = $r['findings'][0];
    T::same([(string) $a, 'provider', 'warning'], [$f['suffix'], $f['entity_type'], $f['severity']]);
    T::contains('5 of 6 calls to “Mock SMM” failed', $f['text']);
    T::contains('3 could not reach the provider or got a server error, 2 were rejected', $f['text']);

    $run = wd_run();
    T::same('alert', $run['verdicts']['provider_errors']);
    T::same("wd:provider_errors:{$a}", wd_alerts('provider_errors')[0]['active_key']);

    wd_smm($a, false);                                       // 6 of 7 fail … and 7 of 8: still ONE alert
    wd_smm($a, false);
    wd_smm($a, false);
    wd_smm($a, false);                                       // 9 of 10 = 90 %
    T::same('critical', Watchdog::evaluate('provider_errors')['findings'][0]['severity']);
});

// ═════════════════════════════ stuck orders ═════════════════════════════════

T::test('watchdog: stuck paid orders — an unknown provider outcome is critical, unsent AUTO orders and old manual orders are warnings', function () {
    wd_reset();
    T::same('ok', Watchdog::evaluate('stuck_orders')['status']);

    an_order('AUTO', 5000, 2000, null, 0, 'PAID');           // paid hours ago, never sent
    $r = Watchdog::evaluate('stuck_orders');
    T::same(['alert', 'warning'], [$r['status'], $r['findings'][0]['severity']]);
    T::contains('1 paid AUTO order not sent after 15 minutes', $r['findings'][0]['text']);
    T::contains('is the cron job running', $r['findings'][0]['text']);

    an_order('MANUAL', 5000, 0, Tz::addDays(Tz::today(), -2), 0, 'MANUAL_PENDING');
    T::contains('1 manual order waiting more than 24 hours for approval', Watchdog::evaluate('stuck_orders')['findings'][0]['text']);

    $o = an_order('AUTO', 5000, 2000, null, 0, 'FAILED');
    Db::update('orders', ['failure_kind' => 'ambiguous'], 'id = ?', [$o]);
    $r = Watchdog::evaluate('stuck_orders');
    T::same('critical', $r['findings'][0]['severity']);
    T::contains('1 order with an unknown provider outcome', $r['findings'][0]['text']);
    T::contains('never resend before checking its panel', $r['findings'][0]['text']);

    // raised by a real run; once the orders are dealt with, two quiet runs resolve it
    wd_run();
    T::same(['critical', 'open'], [wd_alerts('stuck_orders')[0]['severity'], wd_alerts('stuck_orders')[0]['status']]);
    Db::exec("UPDATE orders SET status = 'COMPLETED', failure_kind = NULL, recovery_status = NULL");
    wd_run();
    T::same('open', wd_alerts('stuck_orders')[0]['status']);
    wd_run();
    T::same(['resolved', 'auto'], [wd_alerts('stuck_orders')[0]['status'], wd_alerts('stuck_orders')[0]['resolution']]);
});

T::test('watchdog: an order the recovery queue is already retrying with back-off is not "stuck"', function () {
    wd_reset();
    $o = an_order('AUTO', 5000, 2000, null, 0, 'PAID');
    Db::update('orders', ['next_attempt_at' => Clock::sql(Clock::now() + 600)], 'id = ?', [$o]);
    T::same('ok', Watchdog::evaluate('stuck_orders')['status'], 'a retry is scheduled in the future: it is moving');
});

// ═════════════════════════════ rising provider cost ═════════════════════════

function wd_cost_order(int $daysAgo, int $costPaise, bool $final = true, int $qty = 1000): void
{
    $id = an_order('AUTO', 5000, $costPaise, Tz::addDays(Tz::today(), -$daysAgo));
    Db::update('orders', ['provider_cost_final' => $final ? 1 : 0, 'quantity' => $qty], 'id = ?', [$id]);
}

T::test('watchdog: a rise in the provider’s final charge per 1,000 is reported — estimates and thin history are not compared', function () {
    wd_reset();
    T::same('insufficient', Watchdog::evaluate('provider_cost')['status']);

    foreach ([5, 6, 7, 8] as $d) {
        wd_cost_order($d, 1000);                              // ₹10.00 per 1,000 before
    }
    foreach ([1, 1, 2] as $d) {
        wd_cost_order($d, 1000);                              // … and the same now
    }
    $r = Watchdog::evaluate('provider_cost');
    T::same('ok', $r['status']);
    T::contains('1 service compared', $r['summary']);

    wd_reset();
    foreach ([5, 6, 7, 8] as $d) {
        wd_cost_order($d, 1000);
    }
    foreach ([1, 1, 2] as $d) {
        wd_cost_order($d, 1500);                              // ₹15.00: +50 %
    }
    $r = Watchdog::evaluate('provider_cost');
    T::same(['alert', 'warning'], [$r['status'], $r['findings'][0]['severity']]);
    T::contains('₹10.00 → ₹15.00 (+50.0%)', $r['findings'][0]['text']);

    wd_reset();
    foreach ([5, 6, 7, 8] as $d) {
        wd_cost_order($d, 1000, false);                       // the provider never reported a final charge: these are the service's estimate
    }
    foreach ([1, 1, 2] as $d) {
        wd_cost_order($d, 1500, false);
    }
    T::same('insufficient', Watchdog::evaluate('provider_cost')['status'], 'an estimate cannot "rise"');
});

// ═════════════════════════════ missing cost data ════════════════════════════

T::test('watchdog: missing cost data — unknown order costs and an unset fee model are warnings, a service without a cost alone is information', function () {
    wd_reset();
    $r = Watchdog::evaluate('missing_costs');
    T::same(['alert', 'info'], [$r['status'], $r['findings'][0]['severity']], 'the seeded catalogue has no costs yet, and nothing has sold');
    T::contains('active services have no cost set', $r['findings'][0]['text']);

    wd_costs_set();
    T::same('ok', Watchdog::evaluate('missing_costs')['status']);

    an_order('AUTO', 5000, 0, null, 0, 'COMPLETED');          // paid, delivered, and nobody knows what it cost
    $r = Watchdog::evaluate('missing_costs');
    T::same(['alert', 'warning'], [$r['status'], $r['findings'][0]['severity']]);
    T::contains('1 paid order of the last 30 days has an unknown cost', $r['findings'][0]['text']);

    Db::exec('DELETE FROM payments');
    Db::exec('DELETE FROM orders');
    Settings::set('finance.fee_mode', 'unset');
    an_order('AUTO', 5000, 2000, null, 0, 'COMPLETED');
    $r = Watchdog::evaluate('missing_costs');
    T::same('warning', $r['findings'][0]['severity']);
    T::contains('payment fee model is not set up', $r['findings'][0]['text']);
});

T::test('watchdog: the "unknown cost" test agrees with Finance’s own incompleteness rule', function () {
    wd_reset();
    wd_costs_set();                                           // fee model "none": Finance::incompleteSql() then has no fee clause
    an_order('AUTO', 5000, 0, null, 0, 'COMPLETED');          // unknown
    an_order('AUTO', 5000, 700, null, 0, 'COMPLETED');        // known (estimate)
    an_order('MANUAL', 5000, 0, null, 0, 'COMPLETED');        // no fulfilment line: unknown
    $viaFinance = (int) Db::val('SELECT COUNT(*) FROM orders o WHERE o.paid_at IS NOT NULL AND ' . \App\Services\Finance::incompleteSql('o'));
    $r = Watchdog::evaluate('missing_costs');
    T::same(2, $viaFinance);
    T::same(2, $r['values']['orders_unknown_cost']);
});

// ═════════════════════════════ drop in verified sales ═══════════════════════

function wd_sales(int $perDay, int $daysBack, int $amount = 10000): void
{
    for ($d = 1; $d <= $daysBack; $d++) {
        for ($i = 0; $i < $perDay; $i++) {
            an_order('MANUAL', $amount, 0, Tz::addDays(Tz::today(), -$d));
        }
    }
}

T::test('watchdog: a drop in verified sales — compared with the usual level, only when there IS a usual level', function () {
    wd_reset();
    T::same('insufficient', Watchdog::evaluate('sales_drop')['status'], 'a brand-new shop has no usual level');

    wd_sales(1, 7);                                           // one order a day: below the "at least 3 a day" needed to judge
    $r = Watchdog::evaluate('sales_drop');
    T::same('insufficient', $r['status']);
    T::contains('at least 3', $r['summary']);

    wd_reset();
    wd_sales(5, 7);                                           // ₹500 a day for a week
    an_order('MANUAL', 10000, 0);
    an_order('MANUAL', 10000, 0);                             // today: ₹200  → 60 % under the usual ₹500
    $r = Watchdog::evaluate('sales_drop');
    T::same(['alert', 'warning'], [$r['status'], $r['findings'][0]['severity']]);
    T::contains('60.0% below the usual ₹500.00 a day', $r['findings'][0]['text']);
    T::contains('A quiet day of the week', $r['findings'][0]['text']);

    wd_reset();
    wd_sales(5, 7);
    an_order('MANUAL', 10000, 0);                             // ₹100 today: 80 % down → critical
    T::same('critical', Watchdog::evaluate('sales_drop')['findings'][0]['severity']);

    wd_reset();
    wd_sales(5, 7);
    for ($i = 0; $i < 5; $i++) {
        an_order('MANUAL', 10000, 0);                         // as usual
    }
    T::same('ok', Watchdog::evaluate('sales_drop')['status']);
});

// ═════════════════════════════ conversion rate ══════════════════════════════

/** $days days (ending yesterday) of visitors: $qualified qualified a day, of which $paying paid. */
function wd_visitors(int $fromAgo, int $toAgo, int $qualified, int $paying): void
{
    for ($d = $fromAgo; $d >= $toAgo; $d--) {
        $day = Tz::addDays(Tz::today(), -$d);
        an_visitors($qualified, $qualified, $day);
        Db::exec('UPDATE visitor_days SET payment_succeeded = 1 WHERE day = ? AND qualified = 1 ORDER BY id LIMIT ' . $paying, [$day]);
    }
}

T::test('watchdog: a sharp change in conversion — a fall is a warning, a rise is information, thin traffic is "insufficient"', function () {
    wd_reset();
    wd_visitors(17, 4, 10, 1);                                // the 14 days before: 140 qualified, 14 paying (10.0 %)
    wd_visitors(3, 1, 5, 0);                                  // the last 3 days: only 15 qualified
    $r = Watchdog::evaluate('conversion');
    T::same('insufficient', $r['status']);
    T::contains('at least 100 qualified visitors', $r['summary']);

    wd_reset();
    wd_visitors(17, 4, 10, 1);
    wd_visitors(3, 1, 40, 2);                                 // 120 qualified, 6 paying: 5.0 % against 10.0 %: −50 %
    $r = Watchdog::evaluate('conversion');
    T::same(['alert', 'warning', 'down'], [$r['status'], $r['findings'][0]['severity'], $r['findings'][0]['suffix']]);
    T::contains('5.0% now against 10.0% before (-50.0%)', $r['findings'][0]['text']);
    T::contains('not proof of cause', $r['findings'][0]['text']);

    wd_reset();
    wd_visitors(17, 4, 10, 1);
    wd_visitors(3, 1, 40, 8);                                 // 24 paying of 120: 20.0 % against 10.0 %: +100 %
    $r = Watchdog::evaluate('conversion');
    T::same(['alert', 'info', 'up'], [$r['status'], $r['findings'][0]['severity'], $r['findings'][0]['suffix']]);
    T::contains('tracking or traffic-mix change', $r['findings'][0]['text']);

    wd_reset();
    wd_visitors(17, 4, 10, 1);
    wd_visitors(3, 1, 40, 4);                                 // 10.0 % against 10.0 %
    T::same('ok', Watchdog::evaluate('conversion')['status']);
});

// ═════════════════════════════ scheduled jobs ═══════════════════════════════

function wd_job_run(string $job, string $status, int $agoSeconds, ?string $category = null, string $message = ''): void
{
    Db::insert('job_runs', [
        'job' => $job, 'trigger_source' => 'cron', 'dry_run' => 0, 'status' => $status, 'started_at' => Clock::sql(Clock::now() - $agoSeconds), 'finished_at' => Clock::sql(Clock::now() - $agoSeconds + 3),
        'duration_ms' => 3000, 'error_category' => $category, 'error_message' => $message !== '' ? $message : null,
    ]);
}

T::test('watchdog: scheduled jobs — repeated failures and a job that stopped running are reported; a job that never ran is not an alarm', function () {
    wd_reset();
    T::same('insufficient', Watchdog::evaluate('cron_jobs')['status'], 'no run history at all');

    wd_job_run('analytics', 'ok', 60);
    T::same('ok', Watchdog::evaluate('cron_jobs')['status']);

    wd_job_run('payments', 'failed', 3000, 'network', 'cURL error 28: timed out');
    wd_job_run('payments', 'failed', 2000, 'network', 'cURL error 28: timed out');
    T::same('ok', Watchdog::evaluate('cron_jobs')['status'], 'two failures are under the limit of three');
    wd_job_run('payments', 'failed', 1000, 'network', 'cURL error 28: timed out');
    $r = Watchdog::evaluate('cron_jobs');
    $f = array_values(array_filter($r['findings'], static fn(array $x): bool => $x['suffix'] === 'failed:payments'))[0];
    T::same(['critical', 'job', 'payments'], [$f['severity'], $f['entity_type'], $f['entity_id']], 'its last three runs in a row failed');
    T::contains('failed 3 times in the last 6 hours', $f['text']);
    T::contains('Last error (', $f['text']);

    // recovery: an old success is overdue (5-minute job, nothing for 3 hours)
    wd_job_run('recovery', 'ok', 3 * 3600);
    $r = Watchdog::evaluate('cron_jobs');
    $overdue = array_values(array_filter($r['findings'], static fn(array $x): bool => $x['suffix'] === 'overdue:recovery'));
    T::same(1, count($overdue));
    T::contains('has not completed since', $overdue[0]['text']);
    T::contains('Check the cron job', $overdue[0]['text']);
});

T::test('watchdog: the watchdog does not report on itself', function () {
    wd_reset();
    wd_job_run('analytics', 'ok', 60);
    for ($i = 0; $i < 5; $i++) {
        wd_job_run('watchdog', 'failed', 100 + $i, 'logic', 'boom');
    }
    $r = Watchdog::evaluate('cron_jobs');
    T::same('ok', $r['status']);
});

// ═════════════════════════════ application errors ═══════════════════════════

function wd_logdir(): string
{
    $dir = sys_get_temp_dir() . '/smb-wd-log-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    return $dir;
}

function wd_logline(string $dir, string $level, string $message, int $agoSeconds): void
{
    $ts = Clock::now() - $agoSeconds;
    file_put_contents($dir . '/app-' . gmdate('Y-m-d', $ts) . '.log', json_encode(['ts' => gmdate('c', $ts), 'level' => $level, 'message' => $message, 'context' => [], 'req' => 'cli']) . "\n", FILE_APPEND);
}

T::test('watchdog: application errors are counted from the log — only errors, only inside the window — and the text carries no path or trace', function () {
    wd_reset();
    $dir = wd_logdir();
    Watchdog::$logDir = $dir;
    for ($i = 0; $i < 4; $i++) {
        wd_logline($dir, 'error', 'Could not record SMM transaction', 120);
    }
    wd_logline($dir, 'warning', 'AUTO order could not be submitted', 100);       // a warning is not an error
    wd_logline($dir, 'error', 'Old problem', 5 * 3600);                          // outside the window
    $r = Watchdog::evaluate('app_errors');
    T::same('ok', $r['status']);
    T::contains('4 errors in the last 60 minutes', $r['summary']);

    wd_logline($dir, 'error', 'Failed in ' . SMB_ROOT . '/app/Services/Jobs.php with token KEYabcdefghijklmnopqrstuvwxyz0123456789', 60);
    $r = Watchdog::evaluate('app_errors');
    T::same(['alert', 'warning'], [$r['status'], $r['findings'][0]['severity']]);
    $text = $r['findings'][0]['text'];
    T::contains('5 errors were written to the application log', $text);
    T::contains('“Could not record SMM transaction” ×4', $text);
    T::false(str_contains($text, SMB_ROOT), 'no server path');
    T::false(str_contains($text, 'KEYabcdefghij'), 'no token');

    // a scheduled-job run that failed on the database counts too
    wd_job_run('payments', 'failed', 300, 'database', 'A database error stopped the run (details are in the server log).');
    T::same(['alert', 6], [Watchdog::evaluate('app_errors')['status'], Watchdog::evaluate('app_errors')['values']['logged_errors'] + Watchdog::evaluate('app_errors')['values']['job_database_errors']]);

    for ($i = 0; $i < 16; $i++) {
        wd_logline($dir, 'error', 'Another failure', 30);
    }
    T::same('critical', Watchdog::evaluate('app_errors')['findings'][0]['severity'], '22 errors is over four times the limit of five');
});

T::test('watchdog: an unreadable log folder is "unavailable" — the check does not pretend there were no errors', function () {
    wd_reset();
    Watchdog::$logDir = sys_get_temp_dir() . '/smb-wd-no-such-folder-' . bin2hex(random_bytes(4));
    $r = Watchdog::evaluate('app_errors');
    T::same(['unavailable', []], [$r['status'], $r['findings']]);
    T::contains('cannot be read', $r['summary']);
    T::same('unavailable', wd_run()['verdicts']['app_errors']);
    T::same([], wd_alerts('app_errors'), 'an unreadable log is not "no errors", and it is not an alert either');
});

// ═════════════════════════════ financial discrepancies ══════════════════════

T::test('watchdog: financial discrepancies — revenue without a verified payment, a differing paid amount and a refund mismatch are reported', function () {
    wd_reset();
    wd_costs_set();
    an_order('MANUAL', 5000, 0);
    T::same('ok', Watchdog::evaluate('finance')['status'], 'an order and its verified payment agree');

    $lonely = an_order('MANUAL', 5000, 0);
    Db::exec('DELETE FROM payments WHERE order_id = ?', [$lonely]);
    $r = Watchdog::evaluate('finance');
    T::same(['alert', 'critical'], [$r['status'], $r['findings'][0]['severity']]);
    T::contains('1 paid order without a verified payment', $r['findings'][0]['text']);
    T::contains('Nothing was changed automatically', $r['findings'][0]['text']);

    wd_reset();
    wd_costs_set();
    $o = an_order('MANUAL', 5000, 0);
    Db::exec('UPDATE payments SET paid_amount_paise = 4000 WHERE order_id = ?', [$o]);
    $r = Watchdog::evaluate('finance');
    T::contains('1 payment whose verified amount differs from the order', $r['findings'][0]['text']);
    T::same('critical', $r['findings'][0]['severity']);

    wd_reset();
    wd_costs_set();
    $o = an_order('MANUAL', 5000, 0);
    Db::exec("UPDATE orders SET status = 'REFUNDED', refunded_paise = 0 WHERE id = ?", [$o]);
    $r = Watchdog::evaluate('finance');
    T::same('warning', $r['findings'][0]['severity'], 'a refund that does not add up is a warning, not an emergency');
    T::contains('refund status and refunded amount disagree', $r['findings'][0]['text']);
});

// ═════════════════════════════ provider balance (optional) ══════════════════

T::test('watchdog: the provider-balance check is OFF by default and then never contacts the provider', function () {
    wd_reset();
    T::configureSmm();
    $calls = 0;
    Watchdog::$balanceFetcher = static function (array $row) use (&$calls): SmmResponse {
        $calls++;
        return new SmmResponse(SmmResponse::OK, ['balance' => '12.50', 'currency' => 'INR'], 200, '', 4, '{}');
    };
    T::false(Watchdog::enabled()['provider_balance']);
    $r = wd_run();
    T::same(['disabled', 0], [$r['verdicts']['provider_balance'], $calls]);
    T::same([], wd_alerts('provider_balance'));
});

T::test('watchdog: provider balance — no provider is "unavailable", a failed call is "unavailable", a low balance alerts, and the provider is asked at most once an hour', function () {
    wd_reset();
    $admin = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];
    Watchdog::saveSettings(['enabled' => ['provider_balance' => true], 'thresholds' => ['balance_min' => 100]], $admin);
    $r = Watchdog::evaluate('provider_balance');
    T::same('unavailable', $r['status']);
    T::contains('No SMM provider is connected', $r['summary']);

    $pid = T::configureSmm();
    $calls = 0;
    $answer = new SmmResponse(SmmResponse::UNREACHABLE, null, null, 'Network error (7): Failed to connect', 5, '');
    Watchdog::$balanceFetcher = static function (array $row) use (&$calls, &$answer): SmmResponse {
        $calls++;
        return $answer;
    };
    $r = Watchdog::evaluate('provider_balance');
    T::same(['unavailable', 1], [$r['status'], $calls]);
    T::same([], $r['findings'], 'a provider that cannot be asked produces no invented balance and no alert');

    $answer = new SmmResponse(SmmResponse::OK, ['balance' => '12.50', 'currency' => 'INR'], 200, '', 5, '{"balance":"12.50"}');
    $r = Watchdog::evaluate('provider_balance');
    T::same([2, 'alert', 'critical'], [$calls, $r['status'], $r['findings'][0]['severity']]);
    T::contains('balance of 12.50 INR, below your minimum of 100 INR', $r['findings'][0]['text']);
    T::same('12.50 INR', Db::val('SELECT last_balance FROM smm_providers WHERE id = ?', [$pid]), 'the reading is recorded where the SMM API page shows it');
    T::true((int) Db::val("SELECT COUNT(*) FROM smm_transactions WHERE provider_id = ? AND action = 'balance'", [$pid]) >= 1, 'the call is in the provider call log');

    // within the hour the stored reading is reused; the provider is not asked again
    Clock::advance(900);
    Watchdog::evaluate('provider_balance');
    T::same(2, $calls);
    Clock::advance(3600);
    Watchdog::evaluate('provider_balance');
    T::same(3, $calls);

    // a dry run never calls out
    Clock::advance(7200);
    $r = Watchdog::evaluate('provider_balance', null, null, false);
    T::same(['unavailable', 3], [$r['status'], $calls]);
    wd_run(true);
    T::same(3, $calls);

    // an answer without a usable balance
    $answer = new SmmResponse(SmmResponse::OK, ['currency' => 'INR'], 200, '', 5, '{}');
    $r = Watchdog::evaluate('provider_balance');
    T::same('unavailable', $r['status']);

    // enough money: healthy
    $answer = new SmmResponse(SmmResponse::OK, ['balance' => '2500.00', 'currency' => 'INR'], 200, '', 5, '{}');
    Clock::advance(7200);
    T::same('ok', Watchdog::evaluate('provider_balance')['status']);
});

// ═════════════════════════════ the job as a whole ═══════════════════════════

T::test('watchdog: a dry run evaluates everything and writes nothing', function () {
    wd_reset();
    for ($i = 0; $i < 8; $i++) {
        wd_payment('failed');
    }
    $r = wd_run(true);
    T::same('alert', $r['verdicts']['payment_failures']);
    T::true($r['would_raise_or_refresh'] >= 1);
    T::same([0, []], [(int) Db::val('SELECT COUNT(*) FROM business_alerts'), Watchdog::state()], 'no alert and no stored state');
    $r = wd_run();
    T::same(1, count(wd_alerts('payment_failures')));
    T::true(Watchdog::state() !== [], 'a real run remembers what each check saw');
});

T::test('watchdog: one broken check cannot stop the others', function () {
    wd_reset();
    for ($i = 0; $i < 8; $i++) {
        wd_payment('failed');
    }
    Db::exec('RENAME TABLE webhook_events TO webhook_events_away');
    try {
        $r = wd_run();
    } finally {
        Db::exec('RENAME TABLE webhook_events_away TO webhook_events');
    }
    T::same('unavailable', $r['verdicts']['webhooks']);
    T::same('alert', $r['verdicts']['payment_failures'], 'the checks after the broken one still ran');
    T::true(str_contains(Watchdog::state()['webhooks']['summary'], 'could not run'));
    T::false(str_contains(Watchdog::state()['webhooks']['summary'], 'webhook_events'), 'no table names in what is shown');
});

T::test('watchdog: the job is registered, runs under the job runner and records its verdicts', function () {
    wd_reset();
    T::true(isset(Jobs::REGISTRY['watchdog']) && Jobs::exists('watchdog'));
    T::same(900, Jobs::REGISTRY['watchdog']['every']);
    for ($i = 0; $i < 8; $i++) {
        wd_payment('failed');
    }
    $run = Jobs::run('watchdog', ['source' => 'admin']);
    T::same('ok', $run['status']);
    T::same('alert', $run['result']['verdicts']['payment_failures']);
    $row = Db::one("SELECT status, result_json FROM job_runs WHERE job = 'watchdog'");
    T::true(strlen((string) $row['result_json']) < 4000, 'the stored result fits the history column untruncated');
    T::same('alert', json_decode((string) $row['result_json'], true)['verdicts']['payment_failures']);
    $ov = array_values(array_filter(Jobs::overview(), static fn(array $j): bool => $j['job'] === 'watchdog'))[0];
    T::same('ok', $ov['state']);
});

T::test('watchdog: a critical alert is e-mailed once per cooldown when the owner switched e-mail on, and the message carries no secret', function () {
    wd_reset();
    $sent = [];
    Alerts::$mailer = static function (string $to, string $subject, string $body, string $headers) use (&$sent): bool {
        $sent[] = compact('to', 'subject', 'body');
        return true;
    };
    Settings::setMany(['alerts.email_enabled' => true, 'alerts.email_to' => 'owner@example.test', 'alerts.email_min_severity' => 'critical', 'alerts.email_cooldown_min' => 60]);
    for ($i = 0; $i < 9; $i++) {
        wd_payment('failed');
    }
    wd_payment('success');                                   // 90 %: critical
    $about = static function () use (&$sent): array {
        return array_values(array_filter($sent, static fn(array $m): bool => str_contains($m['subject'], 'Many payments are failing')));
    };
    wd_run();
    T::same(1, count($about()));
    T::contains('[CRITICAL]', $about()[0]['subject']);
    T::contains('/admin/alerts', $about()[0]['body']);
    T::contains('no credentials and no customer data', $about()[0]['body']);
    wd_run();                                                // detected again inside the cooldown: counted, not re-sent
    T::same(1, count($about()));
    Clock::advance(3700);                                    // the old payments have left the one-hour window: the problem is still there in fresh ones
    for ($i = 0; $i < 9; $i++) {
        wd_payment('failed', 60);
    }
    wd_payment('success', 60);
    wd_run();
    T::same(2, count($about()), 'after the cooldown a persisting problem is mentioned again');
});

// ═════════════════════════════ settings ═════════════════════════════════════

T::test('watchdog settings: thresholds are validated, saved, audited with before and after — and a switched-off check does not run', function () {
    wd_reset();
    $admin = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];
    $e = T::throws(HttpException::class, fn() => Watchdog::saveSettings(['thresholds' => ['pay_fail_pct' => 3, 'pay_fail_min' => 'abc', 'job_failures' => '5']], $admin), 422);
    T::same(['pay_fail_pct', 'pay_fail_min'], array_keys($e->extra['fields']));
    T::same(40, Watchdog::thresholds()['pay_fail_pct'], 'a rejected save changes nothing');
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'watchdog.settings_changed'"));

    $view = Watchdog::saveSettings(['thresholds' => ['pay_fail_pct' => 25, 'job_failures' => '5', 'unknown_setting' => 9], 'enabled' => ['payment_failures' => false, 'nonsense' => true]], $admin);
    T::same([25, 5], [Watchdog::thresholds()['pay_fail_pct'], Watchdog::thresholds()['job_failures']]);
    T::same(false, $view['enabled']['payment_failures']);
    $audit = json_decode((string) Db::val("SELECT meta_json FROM audit_logs WHERE action = 'watchdog.settings_changed'"), true);
    T::same([40, 25], $audit['changed']['pay_fail_pct']);
    T::same([true, false], $audit['changed']['enabled.payment_failures']);
    T::false(isset($audit['changed']['unknown_setting']));

    // saving the same values again writes nothing
    Watchdog::saveSettings(['thresholds' => ['pay_fail_pct' => 25]], $admin);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'watchdog.settings_changed'"));

    for ($i = 0; $i < 8; $i++) {
        wd_payment('failed');
    }
    $r = wd_run();
    T::same(['disabled', []], [$r['verdicts']['payment_failures'], wd_alerts('payment_failures')], 'switched off: not evaluated, no alert');

    // a lower threshold judges differently
    Watchdog::saveSettings(['enabled' => ['payment_failures' => true], 'thresholds' => ['pay_fail_min' => 20]], $admin);
    T::same('insufficient', Watchdog::evaluate('payment_failures')['status']);
});

// ═════════════════════════════ alerts: e-mail settings ══════════════════════

T::test('alert e-mail settings: validated, saved, audited without the addresses — and a test message goes only to the saved recipients', function () {
    wd_reset();
    $admin = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];
    $e = T::throws(HttpException::class, fn() => Alerts::saveMailSettings(['enabled' => true, 'to' => ''], $admin), 422);
    T::true(isset($e->extra['fields']['to']), 'switching e-mail on needs a recipient');
    $e = T::throws(HttpException::class, fn() => Alerts::saveMailSettings(['to' => 'owner@example.test, not-an-address', 'from' => 'bad', 'min_severity' => 'info', 'cooldown_min' => 2], $admin), 422);
    T::same(['to', 'from', 'min_severity', 'cooldown_min'], array_keys($e->extra['fields']));
    $e = T::throws(HttpException::class, fn() => Alerts::saveMailSettings(['to' => "a@example.test\r\nBcc: x@example.test"], $admin), 422);
    T::true(isset($e->extra['fields']['to']), 'a header-injection attempt is not an address');
    $e = T::throws(HttpException::class, fn() => Alerts::saveMailSettings(['to' => 'a@x.test,b@x.test,c@x.test,d@x.test,e@x.test,f@x.test'], $admin), 422);
    T::contains('5', $e->extra['fields']['to']);
    T::false(Alerts::mailSettings()['enabled'], 'nothing was saved');

    $m = Alerts::saveMailSettings(['enabled' => true, 'to' => 'owner@example.test, ops@example.test', 'from' => 'alerts@example.test', 'min_severity' => 'warning', 'cooldown_min' => 30], $admin);
    T::same([true, 'owner@example.test, ops@example.test', 'warning', 30], [$m['enabled'], $m['to'], $m['min_severity'], $m['cooldown_min']]);
    $audit = (string) Db::val("SELECT meta_json FROM audit_logs WHERE action = 'alerts.mail_settings_changed'");
    T::false(str_contains($audit, 'example.test'), 'the audit log keeps the number of recipients, not the addresses');
    T::contains('"recipients":2', $audit);

    $sent = [];
    Alerts::$mailer = static function (string $to, string $subject, string $body, string $headers) use (&$sent): bool {
        $sent[] = compact('to', 'subject', 'body', 'headers');
        return true;
    };
    $r = Alerts::sendTest($admin);
    T::same([true, 2], [$r['sent'], $r['recipients']]);
    T::same('owner@example.test,ops@example.test', $sent[0]['to']);
    T::contains('[TEST]', $sent[0]['subject']);
    T::false(str_contains($sent[0]['body'], 'KEY'), 'a test message has no secrets');
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'alerts.test_email'"));

    Alerts::$mailer = static fn(string $to, string $subject, string $body, string $headers): bool => false;
    $r = Alerts::sendTest($admin);
    T::same(false, $r['sent']);
    T::contains('refused', $r['message']);

    Alerts::saveMailSettings(['enabled' => false, 'to' => ''], $admin);
    $e = T::throws(HttpException::class, fn() => Alerts::sendTest($admin), 422);
    T::contains('recipient', $e->getMessage());
});

// ═════════════════════════════ alert text length ════════════════════════════

T::test('alerts: a long description is kept whole up to 1,000 characters — not cut mid-sentence at 300 — and is still cleaned of tokens', function () {
    wd_reset();
    $sentence = 'A service with a long name had 12 paid orders and no known cost. ';
    $long = str_repeat($sentence, 6) . 'Token KEYabcdefghijklmnopqrstuvwxyz0123456789 must not survive. The end.';        // ~460 characters
    $a = Alerts::raise('missing_costs', 'warning', 'Cost data is missing', $long);
    $stored = (string) Db::val('SELECT description FROM business_alerts WHERE id = ?', [$a['id']]);
    T::true(mb_strlen($stored) > 400 && mb_strlen($stored) < 600, 'stored whole: ' . mb_strlen($stored));
    T::true(str_ends_with($stored, 'The end.'), 'the last sentence is still there');
    T::false(str_contains($stored, 'KEYabcdefghij'), 'the opaque token is gone');

    $b = Alerts::raise('x_long', 'info', str_repeat('Title ', 60), str_repeat('word ', 400));
    $row = Db::one('SELECT title, description FROM business_alerts WHERE id = ?', [$b['id']]);
    T::same([160, 1000], [mb_strlen($row['title']), mb_strlen($row['description'])], 'a title is cut at 160 characters, a description at 1,000');
    // an error message of a job run keeps its own, shorter limit
    T::same(300, mb_strlen(Jobs::clean(str_repeat('word ', 200))));
});
