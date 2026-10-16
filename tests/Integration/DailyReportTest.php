<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Services\Alerts;
use App\Services\Analytics;
use App\Services\DailyReport;
use App\Services\Finance;
use App\Services\JobContext;
use App\Services\Jobs;
use App\Services\Settings;
use App\Support\Clock;
use App\Support\Tz;

/**
 * The daily report: the numbers of one finished business day (Asia/Kolkata), calculated from the records by fixed rules, stored once per day,
 * every figure labelled measured / estimated / incomplete / unavailable. Time stands still at 2027-01-15 08:00 UTC (13:30 IST).
 */

const DR_ADMIN = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];

function dr_reset(): void
{
    an_reset();
    Settings::set('finance.fee_mode', 'none');
    Db::exec('UPDATE services SET smm_cost_paise = 100, fulfil_cost_paise = 0');
}

function dr_yesterday(): string
{
    return Tz::addDays(Tz::today(), -1);
}

function dr_row(string $day): ?array
{
    return Db::one('SELECT * FROM daily_reports WHERE report_date = ?', [$day]);
}

function dr_count(): int
{
    return (int) Db::val('SELECT COUNT(*) FROM daily_reports');
}

// ═════════════════════════════ the numbers ══════════════════════════════════

T::test('report: the day’s figures equal what the records add up to', function () {
    dr_reset();
    $d = dr_yesterday();
    $a1 = an_order('AUTO', 10000, 6000, $d);
    $a2 = an_order('AUTO', 10000, 6000, $d);
    Db::update('orders', ['provider_cost_final' => 1], 'id IN (?, ?)', [$a1, $a2]);
    $m1 = an_order('MANUAL', 20000, 0, $d, 5000);                 // ₹50 refunded
    an_order('AUTO', 99900, 100, Tz::addDays($d, -1));             // another day: must not leak in
    Db::insert('expenses', ['expense_day' => $d, 'category' => 'hosting', 'amount_paise' => 3000, 'description' => 'server', 'created_by' => 1, 'created_at' => Clock::sql()]);
    Db::insert('expenses', ['expense_day' => $d, 'category' => 'chargeback', 'amount_paise' => 2000, 'description' => 'bank', 'created_by' => 1, 'created_at' => Clock::sql()]);
    Db::insert('ad_daily_spend', ['spend_day' => $d, 'campaign' => '', 'platform' => 'meta', 'amount_paise' => 4000, 'gst_paise' => 0, 'entered_by' => 1, 'imported_at' => Clock::sql()]);

    $s = DailyReport::build($d);
    $m = $s['metrics'];
    // expected values straight from the tables, not through the report's own code path
    $collected = (int) Db::val('SELECT SUM(amount_paise) FROM orders WHERE paid_day = ?', [$d]);
    $refunds = (int) Db::val('SELECT SUM(refunded_paise) FROM orders WHERE paid_day = ?', [$d]);
    $provider = (int) Db::val("SELECT SUM(provider_cost_paise) FROM orders WHERE paid_day = ? AND mode = 'AUTO'", [$d]);
    T::same([40000, 5000, 12000], [$collected, $refunds, $provider]);
    T::same($collected, $m['collected']['value']);
    T::same($refunds, $m['refunds']['value']);
    T::same(2000, $m['chargebacks']['value']);
    T::same($collected - $refunds - 2000, $m['net_revenue']['value']);
    T::same(3, $m['orders_paid']['value']);
    T::same(intdiv($collected, 3) + ($collected % 3 >= 2 ? 1 : 0), $m['aov']['value'], 'average order value = collected ÷ paid orders, rounded half up');
    T::same($provider, $m['provider_cost']['value']);
    T::same(4000, $m['advertising']['value']);
    T::same(3000, $m['expenses']['value']);
    // contribution = collected − refunds − provider cost; net = contribution − chargebacks − expenses − advertising
    T::same($collected - $refunds - $provider, $m['contribution']['value']);
    T::same($collected - $refunds - $provider - 2000 - 3000 - 4000, $m['net_profit']['value']);
    T::same(3, $m['payments_success']['value']);
    // the same figures as the Finance and dashboard code path
    $fin = Analytics::sum([Analytics::computeDays($d, $d)[$d]]);
    T::same([$fin['net_profit_paise'], $fin['revenue_paise'] + $fin['refunds_paise']], [$m['net_profit']['value'], $m['collected']['value']]);
    // everything in the snapshot is an integer, a string or a list: it round-trips through JSON unchanged
    T::same($s, json_decode(json_encode($s), true));
});

T::test('report: the day is an Asia/Kolkata day — midnight to midnight IST, not UTC', function () {
    dr_reset();
    $d = dr_yesterday();
    $s = DailyReport::build($d);
    T::same('Asia/Kolkata', $s['timezone']);
    T::same(gmdate('Y-m-d H:i:s', Tz::dayStart($d)), $s['window_utc'][0]);
    T::same(gmdate('Y-m-d H:i:s', Tz::dayStart($d) + 86400), $s['window_utc'][1]);
    // IST midnight is 18:30 UTC the evening before
    T::same(substr(Tz::addDays($d, -1), 0, 10) . ' 18:30:00', $s['window_utc'][0]);

    // 23:59:59 IST belongs to the day, 00:00:01 IST to the next
    $late = an_order('MANUAL', 10000, 0, $d);
    Db::update('orders', ['paid_at' => Clock::sql(Tz::dayStart($d) + 86399), 'paid_day' => Tz::dayOf(Tz::dayStart($d) + 86399)], 'id = ?', [$late]);
    $next = an_order('MANUAL', 30000, 0, $d);
    Db::update('orders', ['paid_at' => Clock::sql(Tz::dayStart($d) + 86401), 'paid_day' => Tz::dayOf(Tz::dayStart($d) + 86401)], 'id = ?', [$next]);
    T::same(10000, DailyReport::build($d)['metrics']['collected']['value']);
    T::same(30000, DailyReport::build(Tz::addDays($d, 1))['metrics']['collected']['value']);

    // "yesterday" follows the IST calendar: at 19:00 UTC it is already tomorrow in Kolkata
    Clock::setFixed(strtotime('2027-01-15 17:00:00 UTC'));              // 22:30 IST on the 15th
    T::same('2027-01-14', dr_yesterday());
    Clock::setFixed(strtotime('2027-01-15 19:00:00 UTC'));              // 00:30 IST on the 16th
    T::same('2027-01-15', dr_yesterday());
    T::throws(HttpException::class, fn() => DailyReport::assertDay('2027-01-16'), 422);
});

// ═════════════════════════════ measured / estimated / incomplete / unavailable ═

T::test('report: every figure says whether it is measured, estimated, incomplete or unavailable', function () {
    dr_reset();
    $d = dr_yesterday();

    // an empty day: sales figures are measured zeros, the ratios cannot be calculated
    $m = DailyReport::build($d)['metrics'];
    T::same(['measured', 'unavailable', 'unavailable', 'unavailable'], [$m['collected']['basis'], $m['aov']['basis'], $m['margin']['basis'], $m['conversion']['basis']]);
    T::same(null, $m['aov']['value']);
    T::contains('No paid orders', $m['aov']['note']);

    // a final provider charge is measured
    $o = an_order('AUTO', 10000, 6000, $d);
    Db::update('orders', ['provider_cost_final' => 1], 'id = ?', [$o]);
    T::same('measured', DailyReport::build($d)['metrics']['provider_cost']['basis']);

    // the service's standard cost stands in until the panel reports a charge: estimated
    $est = an_order('AUTO', 10000, 6000, $d);
    $m = DailyReport::build($d)['metrics'];
    T::same(['estimated', 'estimated'], [$m['provider_cost']['basis'], $m['net_profit']['basis']]);
    T::contains('standard cost', $m['provider_cost']['note']);

    // nobody knows what an order cost: incomplete, and said so
    an_order('AUTO', 10000, 0, $d);
    $s = DailyReport::build($d);
    T::same(['incomplete', 'incomplete'], [$s['metrics']['provider_cost']['basis'], $s['metrics']['net_profit']['basis']]);
    T::same('incomplete', $s['quality']['status']);
    T::contains('no known provider cost', $s['metrics']['provider_cost']['note']);
    T::true((bool) array_filter($s['highlights'], static fn(array $h): bool => str_contains($h['text'], 'profit is incomplete') && str_contains($h['text'], 'counts every unknown cost as ₹0')), 'the plain-language summary says so too');

    // a payment-fee model: lines come from the model, never from the gateway
    Db::exec('DELETE FROM payments');
    Db::exec('DELETE FROM orders');
    Settings::setMany(['finance.fee_mode' => 'percent', 'finance.fee_percent_bps' => 200]);
    $o = an_order('MANUAL', 10000, 0, $d);
    Finance::snapshotAtPayment(Db::one('SELECT * FROM orders WHERE id = ?', [$o]), 10000, Tz::dayStart($d) + 36000);
    $m = DailyReport::build($d)['metrics'];
    T::same([200, 'estimated'], [$m['payment_fees']['value'], $m['payment_fees']['basis']]);
    T::contains('gateway reports no fee', $m['payment_fees']['note']);

    // advertising: the CPC estimate is labelled as such, actual spend is measured
    an_visitors(40, 40, $d);
    $m = DailyReport::build($d)['metrics'];
    T::same(['estimated', 40 * 108], [$m['advertising']['basis'], $m['advertising']['value']]);
    Db::insert('ad_daily_spend', ['spend_day' => $d, 'campaign' => '', 'platform' => 'meta', 'amount_paise' => 3000, 'gst_paise' => 0, 'entered_by' => 1, 'imported_at' => Clock::sql()]);
    $m = DailyReport::build($d)['metrics'];
    T::same(['measured', 3000], [$m['advertising']['basis'], $m['advertising']['value']], 'actual spend replaces the estimate, never added to it');

    // conversion needs at least 30 qualified visitors
    T::same('measured', $m['conversion']['basis']);
    Db::exec('DELETE FROM visitor_days');
    an_visitors(10, 10, $d);
    $m = DailyReport::build($d)['metrics'];
    T::same(['unavailable', null], [$m['conversion']['basis'], $m['conversion']['value']]);
    T::contains('at least 30', $m['conversion']['note']);
});

T::test('report: orders are grouped by where they are now, payments by outcome', function () {
    dr_reset();
    $d = dr_yesterday();
    an_order('AUTO', 5000, 100, $d, 0, 'COMPLETED');
    an_order('AUTO', 5000, 100, $d, 0, 'IN_PROGRESS');
    an_order('AUTO', 5000, 100, $d, 0, 'PROCESSING');
    an_order('MANUAL', 5000, 0, $d, 0, 'MANUAL_PENDING');
    an_order('AUTO', 5000, 100, $d, 0, 'FAILED');
    an_order('AUTO', 5000, 100, $d, 0, 'PARTIAL');
    Db::insert('payments', ['ref' => bin2hex(random_bytes(16)), 'order_id' => (int) Db::val('SELECT MIN(id) FROM orders'), 'provider' => 'alpha_upi', 'gateway_order_id' => 'DRX1', 'amount_paise' => 5000, 'status' => 'failed', 'expires_at' => Clock::sql(), 'created_day' => $d, 'created_at' => Clock::sql(), 'updated_at' => Clock::sql()]);
    Db::insert('payments', ['ref' => bin2hex(random_bytes(16)), 'order_id' => (int) Db::val('SELECT MIN(id) FROM orders'), 'provider' => 'alpha_upi', 'gateway_order_id' => 'DRX2', 'amount_paise' => 5000, 'status' => 'expired', 'expires_at' => Clock::sql(), 'created_day' => $d, 'created_at' => Clock::sql(), 'updated_at' => Clock::sql()]);
    Db::insert('payments', ['ref' => bin2hex(random_bytes(16)), 'order_id' => (int) Db::val('SELECT MIN(id) FROM orders'), 'provider' => 'alpha_upi', 'gateway_order_id' => 'DRX3', 'amount_paise' => 5000, 'status' => 'pending', 'expires_at' => Clock::sql(), 'created_day' => $d, 'created_at' => Clock::sql(), 'updated_at' => Clock::sql()]);
    $m = DailyReport::build($d)['metrics'];
    T::same([6, 1, 2, 1, 1], [$m['orders_paid']['value'], $m['orders_completed']['value'], $m['orders_processing']['value'], $m['orders_waiting']['value'], $m['orders_failed']['value']]);
    T::contains('1 on this day', $m['orders_failed']['note'], 'a partial delivery is called out, not folded into "failed"');
    T::same([6, 1, 1, 1], [$m['payments_success']['value'], $m['payments_pending']['value'], $m['payments_failed']['value'], $m['payments_expired']['value']]);
});

T::test('report: the day is compared with the day before — and with nothing before the shop existed', function () {
    dr_reset();
    $d = dr_yesterday();
    $before = Tz::addDays($d, -1);
    an_visitors(3, 3, Tz::addDays($before, -1));                       // an earlier day with visitors but no sale: the first day with data
    an_order('MANUAL', 10000, 0, $before);
    an_order('MANUAL', 10000, 0, $before);
    an_order('MANUAL', 30000, 0, $d);
    $c = DailyReport::build($d)['comparison'];
    T::same([$before, true], [$c['previous_date'], $c['available']]);
    $rows = array_column($c['rows'], null, 'key');
    T::same([30000, 20000, 10000, 500, 'up'], [$rows['collected']['value'], $rows['collected']['previous'], $rows['collected']['delta'], $rows['collected']['delta_pct_tenths'], $rows['collected']['direction']], '+50.0 %');
    T::same([1, 2, -1, 'down'], [$rows['orders_paid']['value'], $rows['orders_paid']['previous'], $rows['orders_paid']['delta'], $rows['orders_paid']['direction']]);

    // a zero before: the change is shown, a percentage of zero is not (it would be infinite)
    $c = array_column(DailyReport::build($before)['comparison']['rows'], null, 'key');
    T::same([0, 20000, null, 'up'], [$c['collected']['previous'], $c['collected']['delta'], $c['collected']['delta_pct_tenths'], $c['collected']['direction']]);

    // the very first day with data has no earlier day to compare with
    $first = Analytics::firstDay();
    $cmp = DailyReport::build($first)['comparison'];
    T::same([false, []], [$cmp['available'], $cmp['rows']]);
});

T::test('report: services — most profitable, loss-making and those with a missing cost — and unresolved alerts at the time of building', function () {
    dr_reset();
    $d = dr_yesterday();
    an_order('MANUAL', 20000, 0, $d, 0, 'COMPLETED', 'ig-followers-50');
    an_order('AUTO', 5000, 9000, $d, 0, 'COMPLETED', 'ig-likes-100');
    $s = DailyReport::build($d);
    T::same(1, count($s['services']['most_profitable']));
    T::same(20000, $s['services']['most_profitable'][0]['contribution']);
    T::same(1, count($s['services']['loss_making']));
    T::same(-4000, $s['services']['loss_making'][0]['contribution']);
    T::true((bool) array_filter($s['highlights'], static fn(array $h): bool => str_contains($h['text'], 'lost money')));

    Alerts::raise('stuck_orders', 'critical', 'Paid orders are stuck', '2 orders.');
    Alerts::raise('sales_drop', 'warning', 'Verified sales have dropped', 'down');
    $a = DailyReport::build($d)['alerts'];
    T::same([2, 1, 2], [$a['counts']['total'], $a['counts']['critical'], count($a['unresolved'])]);
    T::same('Paid orders are stuck', $a['unresolved'][0]['title'], 'critical first');
    T::true((bool) array_filter(DailyReport::build($d)['highlights'], static fn(array $h): bool => str_contains($h['text'], '2 unresolved watchdog alerts (1 critical)')));
});

T::test('report: services with a missing cost are listed only when they sold on the day — idle ones without a cost are counted, not listed', function () {
    dr_reset();
    $d = dr_yesterday();
    Db::exec("UPDATE services SET fulfil_cost_paise = NULL WHERE code IN ('ig-followers-50', 'yt-subs-50', 'yt-likes-100')");
    an_order('MANUAL', 5000, 0, $d, 0, 'COMPLETED', 'ig-followers-50');     // sold on the day, its cost is not set up
    $s = DailyReport::build($d)['services'];
    T::same(['ig-followers-50'], array_column($s['missing_cost'], 'code'), 'only the service that sold changes this day’s profit');
    T::same(1, $s['missing_cost'][0]['orders']);
    T::same(2, $s['no_cost_unsold'], 'the two active services without a cost that did not sell are only counted');
    // a day with no sale at all: nothing is listed, all three are counted
    $none = DailyReport::build(Tz::addDays($d, -1))['services'];
    T::same([[], 3], [$none['missing_cost'], $none['no_cost_unsold']]);
    // once every cost is set, nothing is listed and nothing is counted
    Db::exec('UPDATE services SET fulfil_cost_paise = 0');
    $ok = DailyReport::build($d)['services'];
    T::same(0, $ok['no_cost_unsold']);
});

T::test('report: API and payment failures of the day come from the records', function () {
    dr_reset();
    T::configureSmm();
    $pid = (int) Db::val('SELECT id FROM smm_providers');
    $d = dr_yesterday();
    $at = Tz::dayStart($d) + 40000;
    Db::insert('smm_transactions', ['provider_id' => $pid, 'action' => 'add', 'ok' => 0, 'http_status' => null, 'error' => 'Network error (28)', 'created_at' => Clock::sql($at)]);
    Db::insert('smm_transactions', ['provider_id' => $pid, 'action' => 'add', 'ok' => 0, 'http_status' => 200, 'error' => 'Incorrect link', 'created_at' => Clock::sql($at)]);
    Db::insert('smm_transactions', ['provider_id' => $pid, 'action' => 'status', 'ok' => 1, 'http_status' => 200, 'created_at' => Clock::sql($at)]);
    Db::insert('smm_transactions', ['provider_id' => $pid, 'action' => 'add', 'ok' => 0, 'http_status' => null, 'error' => 'the day after', 'created_at' => Clock::sql($at + 86400)]);
    foreach (['invalid', 'invalid', 'error', 'mismatch', 'processed'] as $i => $st) {
        Db::insert('webhook_events', ['provider' => 'alpha_upi', 'signature_valid' => $st === 'invalid' ? 0 : 1, 'payload_hash' => hash('sha256', "w{$i}"), 'status' => $st, 'received_at' => Clock::sql($at)]);
    }
    $f = DailyReport::build($d)['failures'];
    T::same([['name' => 'Mock SMM', 'calls' => 3, 'failed' => 2, 'technical' => 1, 'rejected' => 1]], $f['provider_calls']);
    T::same(['received' => 5, 'invalid_signature' => 2, 'unprocessed' => 1, 'mismatch' => 1], $f['webhooks']);
});

// ═════════════════════════════ storing, regenerating, never duplicating ═════

T::test('report: one report per day — a second run creates nothing, a regeneration updates the row in place and audits what changed', function () {
    dr_reset();
    $d = dr_yesterday();
    an_order('MANUAL', 10000, 0, $d);
    $r = DailyReport::generate($d);
    T::same([true, false, 1], [$r['created'], $r['regenerated'], dr_count()]);
    $row = dr_row($d);
    T::same(['cron', 0, 'none', DailyReport::CALC_VERSION], [$row['generated_by'], (int) $row['regenerated_count'], $row['ai_status'], $row['calc_version']]);
    T::same(10000, json_decode($row['snapshot_json'], true)['metrics']['collected']['value']);

    // the cron job again, and again: nothing new
    $r = DailyReport::generate($d);
    T::same([false, false, 1], [$r['created'], $r['regenerated'], dr_count()]);
    T::same($row['snapshot_json'], dr_row($d)['snapshot_json'], 'untouched');

    // an order is recorded afterwards; commentary exists; an admin regenerates
    Db::update('daily_reports', ['ai_status' => 'ok', 'ai_summary' => 'Sales were fine.', 'ai_provider' => 'x', 'ai_model' => 'y'], 'report_date = ?', [$d]);
    an_order('MANUAL', 25000, 0, $d);
    $r = DailyReport::generate($d, ['by' => 'admin', 'force' => true, 'admin' => DR_ADMIN]);
    T::same([false, true, 1], [$r['created'], $r['regenerated'], dr_count()], 'updated in place, never a second row');
    $keys = array_column($r['changed'], 'key');
    T::true(in_array('collected', $keys, true) && in_array('orders_paid', $keys, true));
    $c = array_column($r['changed'], null, 'key')['collected'];
    T::same([10000, 35000], [$c['before'], $c['after']]);
    $row = dr_row($d);
    T::same(['admin', 1, 'none', null], [$row['generated_by'], (int) $row['regenerated_count'], $row['ai_status'], $row['ai_summary']], 'commentary about numbers that no longer exist is dropped');
    $audit = json_decode((string) Db::val("SELECT meta_json FROM audit_logs WHERE action = 'report.regenerated'"), true);
    T::true($audit['figures_changed'] >= 2 && $audit['changed'][0]['key'] !== '');

    // regenerating with nothing changed is safe and keeps any commentary
    Db::update('daily_reports', ['ai_status' => 'ok', 'ai_summary' => 'Still true.'], 'report_date = ?', [$d]);
    $r = DailyReport::generate($d, ['by' => 'admin', 'force' => true, 'admin' => DR_ADMIN]);
    T::same([[], 2, 'Still true.'], [$r['changed'], (int) dr_row($d)['regenerated_count'], dr_row($d)['ai_summary']]);
    T::same(2, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'report.regenerated'"));
});

T::test('report: a day that is not finished, not a date, or older than the shop cannot be generated', function () {
    dr_reset();
    an_order('MANUAL', 10000, 0, dr_yesterday());
    foreach ([Tz::today(), Tz::addDays(Tz::today(), 3)] as $day) {
        $e = T::throws(HttpException::class, fn() => DailyReport::generate($day), 422);
        T::contains('latest you can generate is ' . dr_yesterday(), $e->getMessage());
    }
    foreach (['yesterday', '2027-13-01', '15-01-2027', ''] as $bad) {
        $e = T::throws(HttpException::class, fn() => DailyReport::generate($bad), 422);
        T::true(isset($e->extra['fields']['date']), $bad);
    }
    $e = T::throws(HttpException::class, fn() => DailyReport::generate('2026-01-01'), 422);
    T::contains('no data before', $e->getMessage());
    T::same(0, dr_count());
});

// ═════════════════════════════ the job ══════════════════════════════════════

T::test('report job: fills every finished day without a report — only those, never today, never before the first day — and is safe to run again', function () {
    dr_reset();
    $yesterday = dr_yesterday();
    an_order('MANUAL', 10000, 0, Tz::addDays($yesterday, -3));          // the first day with data
    an_order('MANUAL', 10000, 0, $yesterday);
    $first = Tz::addDays($yesterday, -3);
    T::same([$first, Tz::addDays($first, 1), Tz::addDays($first, 2), $yesterday], DailyReport::missingDays());

    $dry = DailyReport::runDue(new JobContext(microtime(true) + 60, true));
    T::same([4, 0, 4], [$dry['missing'], count($dry['generated']), count($dry['would_generate'])]);
    T::same(0, dr_count());

    $r = DailyReport::runDue(new JobContext(microtime(true) + 60));
    T::same([$first, Tz::addDays($first, 1), Tz::addDays($first, 2), $yesterday], $r['generated']);
    T::same(4, dr_count());
    T::false((bool) dr_row(Tz::today()), 'a report covers a finished day');
    T::false((bool) dr_row(Tz::addDays($first, -1)), 'nothing before the first day with data');

    $r = DailyReport::runDue(new JobContext(microtime(true) + 60));
    T::same([0, 0, 4], [$r['missing'], count($r['generated']), dr_count()], 'running again creates nothing');

    // the next morning: only the new day
    Clock::advance(86400);
    T::same([Tz::addDays($yesterday, 1)], DailyReport::missingDays());
    // a gap longer than the catch-up window is not back-filled
    Clock::advance(86400 * 20);
    T::same(DailyReport::CATCHUP_DAYS, count(DailyReport::missingDays()));
});

T::test('report job: it is registered, runs under the job runner, and two runs at once cannot make two reports', function () {
    dr_reset();
    an_order('MANUAL', 10000, 0, dr_yesterday());
    T::true(Jobs::exists('report'));
    $run = Jobs::run('report', ['source' => 'admin']);
    T::same(['ok', [dr_yesterday()]], [$run['status'], $run['result']['generated']]);
    $again = Jobs::run('report', ['source' => 'admin']);
    T::same([[], 1], [$again['result']['generated'], dr_count()]);

    // another process holds the job's lock: this run is skipped, not duplicated
    $other = ops_second_connection();
    T::same(1, (int) $other->query("SELECT GET_LOCK('smb:job:report', 0)")->fetchColumn());
    try {
        $skipped = Jobs::run('report');
    } finally {
        $other->query("SELECT RELEASE_LOCK('smb:job:report')");
    }
    T::same('skipped', $skipped['status']);
});

T::test('report job: the one-cron-line setup runs it once per IST day, shortly after 00:10, and retries a failed run only after 15 minutes', function () {
    dr_reset();
    an_order('MANUAL', 10000, 0, dr_yesterday());
    $due = static fn(): array => array_keys(Jobs::runDue(['report']));
    Clock::setFixed(Tz::dayStart(Tz::today()) + 5 * 60);                      // 00:05 IST: not yet
    T::same([], $due());
    Clock::setFixed(Tz::dayStart(Tz::today()) + 11 * 60);                     // 00:11 IST
    T::same(['report'], $due());
    T::same(1, dr_count());
    Clock::setFixed(Tz::dayStart(Tz::today()) + 12 * 60);
    T::same([], $due(), 'once a day: it already ran after 00:10');
    Clock::setFixed(Tz::dayStart(Tz::today()) + 20 * 3600);
    T::same([], $due());

    // the next day it is due again
    Clock::setFixed(Tz::dayStart(Tz::addDays(Tz::today(), 1)) + 11 * 60);
    T::same(['report'], $due());

    // a failed run: not retried every minute, but after 15 minutes
    Clock::setFixed(Tz::dayStart(Tz::addDays(Tz::today(), 2)) + 11 * 60);
    Jobs::register('report', static function (JobContext $c): array { throw new \RuntimeException('boom'); });
    try {
        T::same(['report'], $due());
        Clock::advance(120);
        T::same([], $due(), 'two minutes after a failure');
        Clock::advance(15 * 60);
        T::same(['report'], $due(), 'fifteen minutes after');
    } finally {
        Jobs::unregister('report');
    }
    // overdue only when a day was missed entirely
    $ov = array_values(array_filter(Jobs::overview(), static fn(array $j): bool => $j['job'] === 'report'))[0];
    T::same('failing', $ov['state']);
});

// ═════════════════════════════ reading ══════════════════════════════════════

T::test('report history: newest first, filtered by date, paged — and one report comes back with its definitions', function () {
    dr_reset();
    $y = dr_yesterday();
    for ($i = 0; $i < 25; $i++) {
        an_order('MANUAL', 10000 + $i, 0, Tz::addDays($y, -$i));
    }
    for ($i = 0; $i < 25; $i++) {
        DailyReport::generate(Tz::addDays($y, -$i));
    }
    $p1 = DailyReport::list();
    T::same([25, 2, 20, $y], [$p1['total'], $p1['pages'], count($p1['rows']), $p1['rows'][0]['date']]);
    T::same(5, count(DailyReport::list(['page' => 2])['rows']));
    T::same(3, DailyReport::list(['from' => Tz::addDays($y, -2), 'to' => $y])['total']);
    T::same(1, DailyReport::list(['from' => $y, 'to' => $y])['total']);
    $row = $p1['rows'][0];
    T::same([10000, 1, 'incomplete'], [$row['collected'], $row['orders_paid'], $row['net_profit_basis']], 'a manual order nobody stated a cost for: the profit is incomplete, and the list says so');
    T::same(10000, $row['net_profit']);
    foreach ([['from' => 'x'], ['to' => '2027-02-30']] as $bad) {
        $e = T::throws(HttpException::class, fn() => DailyReport::list($bad), 422);
        T::true(isset($e->extra['fields']), json_encode($bad));
    }

    $one = DailyReport::get($y);
    T::same([$y, DailyReport::CALC_VERSION, 'none'], [$one['date'], $one['calc_version'], $one['ai']['status']]);
    T::true(isset($one['definitions']['net_profit'], $one['sections']['sales'], $one['snapshot']['metrics']['collected']));
    T::same(null, DailyReport::get('2020-01-01'));
    T::same(null, DailyReport::get('nonsense'));
});

T::test('report: no AI is needed — a report is complete without it and says no model took part', function () {
    dr_reset();
    an_order('MANUAL', 10000, 0, dr_yesterday());
    DailyReport::generate(dr_yesterday());
    $row = dr_row(dr_yesterday());
    T::same(['none', null, null, null], [$row['ai_status'], $row['ai_provider'], $row['ai_model'], $row['ai_summary']]);
    $s = json_decode($row['snapshot_json'], true);
    T::true((bool) array_filter($s['caveats'], static fn(string $c): bool => str_contains($c, 'No AI model took part')));
    T::true(isset($s['highlights'][0]['text']));
});
