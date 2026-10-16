<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AdSpend;
use App\Services\Analytics;
use App\Services\Dashboard;
use App\Services\Finance;
use App\Services\FinanceReport;
use App\Services\Fulfilment;
use App\Services\Orders;
use App\Services\Settings;
use App\Services\Settlement;
use App\Support\Clock;
use App\Support\Tz;

/**
 * Profit Intelligence: the fee model and the cost snapshots taken when a payment is verified, the cost status (actual / estimated / incomplete — unknown
 * is never ₹0), append-only cost lines, expenses and chargebacks, the daily numbers (every cost subtracted exactly once), actual versus estimated ad
 * spend, the services table, the reconciliation view and its fixes. Numbers are exact integers of paise.
 */

const FN_ADMIN = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];

function fn_req(): Request { return Request::fake('POST', '/admin/api/finance'); }

/** The admin account id 1 (FN_ADMIN), so the names of who recorded / voided something can be read back. */
function fn_seed_admin(): void
{
    if ((int) Db::val('SELECT COUNT(*) FROM admins WHERE id = 1') === 0) {
        Db::insert('admins', ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'password_hash' => 'x', 'role' => 'owner', 'is_active' => 1, 'must_change_password' => 0, 'password_changed_at' => Clock::sql(), 'created_at' => Clock::sql(), 'updated_at' => Clock::sql()]);
    }
}

/** A real checkout, settled through the real code (the way a verified payment arrives). @return array order row */
function fn_paid(string $code = 'ig-followers-50', ?string $name = null): array
{
    static $n = 0;
    $n++;
    $p = pf_place($code, 'https://www.instagram.com/' . ($name ?? 'fin' . $n) . '/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    Settlement::settle((int) $p['payment']['id'], ['source' => 'webhook', 'amount_paise' => (int) $p['payment']['amount_paise'], 'utr' => 'FNUTR' . $n . strtoupper(bin2hex(random_bytes(3)))]);
    return Orders::byId((int) $p['order']['id']);
}

/** @return list<array<string,mixed>> the order's cost lines (live ones only unless $all) */
function fn_lines(int $orderId, bool $all = false): array
{
    return Db::all('SELECT * FROM order_costs WHERE order_id = ?' . ($all ? '' : ' AND superseded_at IS NULL') . ' ORDER BY id', [$orderId]);
}

/** The status the SQL rule gives (the one every aggregate uses). */
function fn_sql_status(int $orderId): string
{
    return (string) Db::val('SELECT CASE WHEN ' . Finance::incompleteSql('o') . " THEN 'incomplete' WHEN " . Finance::estimatedSql('o') . " THEN 'estimated' ELSE 'actual' END FROM orders o WHERE o.id = ?", [$orderId]);
}

function fn_fee(string $mode, string $pct = '', string $fixed = ''): void
{
    Finance::saveSettings(['fee_mode' => $mode, 'fee_percent' => $pct === '' ? '0' : $pct, 'fee_fixed' => $fixed === '' ? '0' : $fixed], fn_req(), FN_ADMIN);
}

// ═════════════════════════════ the fee model ═════════════════════════════

T::test('finance settings: the fee model is validated, saved and audited; an unset model means the fee is UNKNOWN, not zero', function () {
    an_reset();
    T::same(['fee_mode' => 'unset', 'fee_percent_bps' => 0, 'fee_fixed_paise' => 0, 'ad_gst' => 'cost'], Finance::settings());
    T::same(null, Finance::feeFor(10000), 'unset → unknown');
    T::same('not set up', Finance::feeDescription());
    foreach ([
        [['fee_mode' => 'sometimes'], 'fee_mode'],
        [['fee_mode' => 'percent', 'fee_percent' => '0'], 'fee_percent'],
        [['fee_mode' => 'percent', 'fee_percent' => '25'], 'fee_percent'],
        [['fee_mode' => 'percent', 'fee_percent' => 'x'], 'fee_percent'],
        [['fee_mode' => 'fixed', 'fee_fixed' => '0'], 'fee_fixed'],
        [['fee_mode' => 'fixed', 'fee_fixed' => '5000'], 'fee_fixed'],
        [['fee_mode' => 'fixed', 'fee_fixed' => '1.234'], 'fee_fixed'],
        [['fee_mode' => 'none', 'ad_gst' => 'maybe'], 'ad_gst'],
    ] as [$bad, $field]) {
        $e = T::throws(HttpException::class, fn() => Finance::saveSettings($bad, fn_req(), FN_ADMIN), 422);
        T::true(isset($e->extra['fields'][$field]), $field . ' is named: ' . json_encode($e->extra));
    }
    T::same('unset', Finance::settings()['fee_mode'], 'nothing was saved by the refused attempts');

    // a stored day computed under the old rule is recomputed after the model changes
    $day = Tz::addDays(Tz::today(), -2);
    Analytics::rollup($day, $day);
    T::same(0, (int) Db::val('SELECT dirty FROM analytics_daily WHERE day = ?', [$day]));
    $s = Finance::saveSettings(['fee_mode' => 'percent_plus_fixed', 'fee_percent' => '1.5', 'fee_fixed' => '2.50', 'ad_gst' => 'recoverable'], fn_req(), FN_ADMIN);
    T::same(['fee_mode' => 'percent_plus_fixed', 'fee_percent_bps' => 150, 'fee_fixed_paise' => 250, 'ad_gst' => 'recoverable'], $s);
    T::same(1, (int) Db::val('SELECT dirty FROM analytics_daily WHERE day = ?', [$day]), 'every stored day is marked for recomputation');
    $audits = static fn(): int => (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.settings_changed'");
    T::same(1, $audits());
    // 1.5 % of ₹99 = 148.5 paise → half-up 149, plus ₹2.50
    T::same(149 + 250, Finance::feeFor(9900));
    T::same('1.5% + ₹2.50 per payment', Finance::feeDescription());
    Finance::saveSettings(['fee_mode' => 'percent', 'fee_percent' => '2'], fn_req(), FN_ADMIN);
    T::same(247, Finance::feeFor(12345), '2 % of ₹123.45 = 246.9 → 247');
    T::same(2, $audits());
    Finance::saveSettings(['fee_mode' => 'percent', 'fee_percent' => '2'], fn_req(), FN_ADMIN);
    T::same(2, $audits(), 'saving the same values again is not a change');
    Finance::saveSettings(['fee_mode' => 'none'], fn_req(), FN_ADMIN);
    T::same(0, Finance::feeFor(9900), 'an explicit "no fee" is a known zero');
    T::same(3, $audits());
});

T::test('cost snapshots: the payment fee is estimated from the model at the moment of payment, and a later change never rewrites it', function () {
    pf_setup();
    fn_fee('percent', '2');
    $a = fn_paid();
    $fee = array_values(array_filter(fn_lines((int) $a['id']), fn($l) => $l['cost_type'] === 'payment_fee'));
    T::same(1, count($fee));
    T::same([10, 'rate', 1, (int) $a['id'] . ':payment_fee'], [(int) $fee[0]['amount_paise'], $fee[0]['source'], (int) $fee[0]['is_estimate'], $fee[0]['active_key']], '2 % of ₹5.00 = 10 paise, an estimate from the model');
    fn_fee('fixed', '', '3');
    $b = fn_paid();
    T::same(300, (int) fn_lines((int) $b['id'])[0]['amount_paise'], 'the new order uses the new model');
    T::same(10, (int) fn_lines((int) $a['id'])[0]['amount_paise'], 'the old order keeps the fee it was paid under');
    fn_fee('unset');
    $c = fn_paid();
    T::same([], fn_lines((int) $c['id']), 'an unset model records nothing: the fee is unknown');
    fn_fee('none');
    $d = fn_paid();
    T::same([], fn_lines((int) $d['id']), '"no fee" needs no line — it is a known zero');
});

T::test('cost snapshots: a MANUAL service\'s standard fulfilment cost is taken at payment; none stated means no line (the order stays incomplete)', function () {
    pf_setup();
    fn_fee('none');
    $none = fn_paid();
    T::same([], fn_lines((int) $none['id']));
    T::same('incomplete', fn_sql_status((int) $none['id']), 'a manual order whose delivery cost nobody stated is NOT counted as free-and-complete');
    Db::exec("UPDATE services SET fulfil_cost_paise = 120 WHERE code = 'ig-followers-50'");
    $a = fn_paid();
    $line = fn_lines((int) $a['id'])[0];
    T::same(['fulfilment', 120, 'service_default', 1], [$line['cost_type'], (int) $line['amount_paise'], $line['source'], (int) $line['is_estimate']]);
    T::same('estimated', fn_sql_status((int) $a['id']), 'a configured standard cost is an estimate');
    Db::exec("UPDATE services SET fulfil_cost_paise = 300 WHERE code = 'ig-followers-50'");
    $b = fn_paid();
    T::same(300, (int) fn_lines((int) $b['id'])[0]['amount_paise']);
    T::same(120, (int) fn_lines((int) $a['id'])[0]['amount_paise'], 'changing the service\'s cost does not rewrite an order that was already paid');
    Db::exec("UPDATE services SET fulfil_cost_paise = 0 WHERE code = 'ig-followers-50'");
    $z = fn_paid();
    T::same(0, (int) fn_lines((int) $z['id'])[0]['amount_paise'], '0 is a statement ("it costs nothing directly"), not "unknown"');
});

T::test('cost snapshots: the order is never paid without them (one transaction), and a repeated settlement does not add a second line', function () {
    pf_setup();
    fn_fee('percent', '1');
    $o = fn_paid();
    $pid = (int) Db::val('SELECT id FROM payments WHERE order_id = ?', [$o['id']]);
    $again = Settlement::settle($pid, ['source' => 'poll', 'amount_paise' => 500, 'utr' => (string) Db::val('SELECT provider_txn_id FROM payments WHERE id = ?', [$pid])]);
    T::same(Settlement::ALREADY, $again['result']);
    T::same(1, count(fn_lines((int) $o['id'])), 'one live fee line');
    // the unique active key is the guard even if the code were called twice
    Finance::snapshotAtPayment($o, 500, Clock::now());
    T::same(1, count(fn_lines((int) $o['id'], true)), 'a second snapshot is ignored by the unique key');
});

// ═════════════════════════════ cost status ═════════════════════════════

T::test('cost status: actual / estimated / incomplete — the PHP rule and the SQL rule agree on every kind of order', function () {
    an_reset();
    $line = static function (int $orderId, string $type, int $paise, bool $estimate): void {
        Db::insert('order_costs', ['order_id' => $orderId, 'cost_type' => $type, 'amount_paise' => $paise, 'source' => 'admin', 'is_estimate' => $estimate ? 1 : 0, 'effective_at' => Clock::sql(), 'created_at' => Clock::sql(),
            'active_key' => in_array($type, ['fulfilment', 'payment_fee'], true) ? $orderId . ':' . $type : null]);
    };
    $mk = static function (string $mode, int $cost, bool $final, ?string $providerOrder, string $status = 'COMPLETED'): int {
        $id = an_order($mode, 10000, $cost, null, 0, $status);
        Db::update('orders', ['provider_cost_final' => $final ? 1 : 0, 'provider_order_id' => $providerOrder], 'id = ?', [$id]);
        return $id;
    };
    $cases = [];
    // fee model "none": only the delivery cost matters
    fn_fee('none');
    $cases['AUTO, the provider reported its final charge'] = [$mk('AUTO', 600, true, 'P1'), 'actual'];
    $cases['AUTO, only the service estimate'] = [$mk('AUTO', 600, false, 'P2'), 'estimated'];
    $cases['AUTO at the provider, no cost known at all'] = [$mk('AUTO', 0, false, 'P3'), 'incomplete'];
    $cases['AUTO that never reached the provider (failed): the cost is a known zero'] = [$mk('AUTO', 0, false, null, 'FAILED'), 'actual'];
    $cases['AUTO paid, waiting to be sent: unknown'] = [$mk('AUTO', 0, false, null, 'PAID'), 'incomplete'];
    $m1 = $mk('MANUAL', 0, false, null, 'MANUAL_PENDING');
    $cases['MANUAL without any fulfilment line'] = [$m1, 'incomplete'];
    $m2 = $mk('MANUAL', 0, false, null, 'MANUAL_APPROVED');
    $line($m2, 'fulfilment', 1500, true);
    $cases['MANUAL with an estimated fulfilment line'] = [$m2, 'estimated'];
    $m3 = $mk('MANUAL', 0, false, null, 'MANUAL_APPROVED');
    $line($m3, 'fulfilment', 1500, false);
    $cases['MANUAL with a recorded actual fulfilment line'] = [$m3, 'actual'];
    $m4 = $mk('MANUAL', 0, false, null, 'MANUAL_APPROVED');
    $line($m4, 'fulfilment', 1500, false);
    $line($m4, 'other', 200, true);
    $cases['MANUAL actual + an estimated "other" line'] = [$m4, 'estimated'];
    foreach ($cases as $label => [$id, $expect]) {
        $o = Orders::byId($id);
        $php = Finance::costStatus($o, fn_lines($id));
        T::same($expect, $php['status'], 'PHP: ' . $label);
        T::same($expect, fn_sql_status($id), 'SQL: ' . $label);
        T::same($expect === 'actual', $php['reasons'] === [], 'reasons are given exactly when the figure is not actual: ' . $label);
    }
    // the fee model joins in: with a percentage configured every paid order needs a fee line
    fn_fee('percent', '2');
    foreach ([$m3, $m4] as $id) {
        T::same('incomplete', Finance::costStatus(Orders::byId($id), fn_lines($id))['status'], 'no fee line under a percentage model');
        T::same('incomplete', fn_sql_status($id));
    }
    $line($m3, 'payment_fee', 200, false);
    T::same('actual', Finance::costStatus(Orders::byId($m3), fn_lines($m3))['status'], 'a recorded fee completes it');
    T::same('actual', fn_sql_status($m3));
    fn_fee('unset');
    T::contains('not set up', implode(' ', Finance::costStatus(Orders::byId($m1), fn_lines($m1))['reasons']), 'an unset model is named as the reason');
});

// ═════════════════════════════ cost lines are append-only ═════════════════════════════

T::test('cost lines: a correction ends the old line and adds a new one — validated, audited, on the order\'s timeline, for paid orders only', function () {
    pf_setup();
    fn_fee('none');
    $o = fn_paid();
    $id = (int) $o['id'];
    foreach ([
        [['fulfilment', 'abc', 'why'], 'amount'], [['fulfilment', '-5', 'why'], 'amount'], [['fulfilment', '1.234', 'why'], 'amount'], [['fulfilment', '999999', 'why'], 'amount'],
        [['fulfilment', '10', ''], 'note'], [['fulfilment', '10', 'ab'], 'note'], [['bonus', '10', 'why not'], 'type'],
    ] as [[$type, $amount, $note], $field]) {
        $e = T::throws(HttpException::class, fn() => Finance::recordLine($id, $type, $amount, $note, false, fn_req(), FN_ADMIN), 422);
        T::true(isset($e->extra['fields'][$field]), $field . ': ' . json_encode($e->extra));
    }
    T::same([], fn_lines($id), 'nothing was recorded by the refused attempts');
    $view = Finance::recordLine($id, 'fulfilment', '15.50', 'Paid the supplier ₹15.50 for this order', false, fn_req(), FN_ADMIN);
    T::same(1550, $view['line_costs_paise']);
    T::same('actual', $view['cost_status']);
    $view = Finance::recordLine($id, 'fulfilment', '12', 'The supplier refunded ₹3.50', false, fn_req(), FN_ADMIN);
    T::same(1200, $view['line_costs_paise'], 'the correction replaces the earlier amount');
    $all = fn_lines($id, true);
    T::same([1550, 1200], array_map(fn($l) => (int) $l['amount_paise'], $all), 'the old line is kept as history');
    T::true($all[0]['superseded_at'] !== null && $all[0]['active_key'] === null, 'the old line ended and gave up its live key');
    T::same([false, true], array_map(fn($l) => $l['active'], $view['lines']));
    T::same(2, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.cost_recorded' AND entity_id = ?", [(string) $id]));
    T::same(2, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND note LIKE 'Cost recorded: fulfilment%'", [$id]));
    T::same(1, (int) Db::val('SELECT dirty FROM analytics_daily WHERE day = ?', [$o['paid_day']]), 'the order\'s day will be recomputed');
    // an order nobody paid has no costs
    $p = pf_place('yt-likes-50k', 'https://www.youtube.com/watch?v=abcdefghijk', ['visitor_id' => null, 'session_id' => null], ff_ip());
    T::throws(HttpException::class, fn() => Finance::recordLine((int) $p['order']['id'], 'other', '1', 'no payment yet', false, fn_req(), FN_ADMIN), 409);
    T::throws(HttpException::class, fn() => Finance::recordLine(999999, 'other', '1', 'no such order', false, fn_req(), FN_ADMIN), 404);
});

T::test('cost lines: "other" costs add up and a wrong one is removed with a reason; a fulfilment cost or a fee can only be corrected', function () {
    pf_setup();
    fn_fee('none');
    $o = fn_paid();
    $id = (int) $o['id'];
    Finance::recordLine($id, 'fulfilment', '5', 'standard', false, fn_req(), FN_ADMIN);
    Finance::recordLine($id, 'other', '2', 'packaging', false, fn_req(), FN_ADMIN);
    $v = Finance::recordLine($id, 'other', '3', 'a typo — meant 0.30', false, fn_req(), FN_ADMIN);
    T::same(500 + 200 + 300, $v['line_costs_paise'], 'several "other" lines are allowed');
    $wrong = (int) Db::val("SELECT id FROM order_costs WHERE order_id = ? AND cost_type = 'other' AND amount_paise = 300", [$id]);
    $v = Finance::voidLine($wrong, 'It was ₹0.30', fn_req(), FN_ADMIN);
    T::same(700, $v['line_costs_paise']);
    T::contains('removed: It was', (string) Db::val('SELECT note FROM order_costs WHERE id = ?', [$wrong]));
    T::throws(HttpException::class, fn() => Finance::voidLine($wrong, 'again', fn_req(), FN_ADMIN), 409);
    T::throws(HttpException::class, fn() => Finance::voidLine($wrong + 1000, 'none', fn_req(), FN_ADMIN), 404);
    $ful = (int) Db::val("SELECT id FROM order_costs WHERE order_id = ? AND cost_type = 'fulfilment'", [$id]);
    T::throws(HttpException::class, fn() => Finance::voidLine($ful, 'remove it', fn_req(), FN_ADMIN), 409);
    T::throws(HttpException::class, fn() => Finance::voidLine((int) Db::val("SELECT id FROM order_costs WHERE order_id = ? AND cost_type = 'other' LIMIT 1", [$id]), 'x', fn_req(), FN_ADMIN), 422);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.cost_removed'"));
});

T::test('moving an AUTO order to the manual queue gives it the service\'s standard fulfilment cost', function () {
    ['gid' => $gid] = ff_setup();
    fn_fee('none');
    Db::exec("UPDATE services SET fulfil_cost_paise = 250 WHERE code = 'ig-followers-50'");
    ff_provider_down();
    $o = ff_paid($gid);
    T::same([], fn_lines((int) $o['id']), 'an AUTO order has no fulfilment line');
    ff_provider_up();
    Fulfilment::moveToManual((int) $o['id'], FN_ADMIN, fn_req());
    $l = fn_lines((int) $o['id']);
    T::same(['fulfilment', 250], [$l[0]['cost_type'], (int) $l[0]['amount_paise']]);
    T::same(1, (int) Db::val('SELECT dirty FROM analytics_daily WHERE day = ?', [$o['paid_day']]));
});

// ═════════════════════════════ expenses and chargebacks ═════════════════════════════

T::test('expenses: validated, audited, voided with a reason (never deleted), listed, and the day is recomputed', function () {
    pf_setup();
    fn_seed_admin();
    $o = fn_paid();
    $today = Tz::today();
    $bad = [
        [['day' => Tz::addDays($today, 1), 'category' => 'tools', 'amount' => '5'], 'day'], [['day' => 'x', 'category' => 'tools', 'amount' => '5'], 'day'], [['day' => $today, 'category' => 'payment_fee', 'amount' => '5'], 'category'],
        [['day' => $today, 'category' => 'tools', 'amount' => '0'], 'amount'], [['day' => $today, 'category' => 'tools', 'amount' => '1.999'], 'amount'], [['day' => $today, 'category' => 'tools', 'amount' => '99999999'], 'amount'],
        [['day' => $today, 'category' => 'other', 'amount' => '5'], 'description'], [['day' => $today, 'category' => 'chargeback', 'amount' => '5'], 'order'],
        [['day' => $today, 'category' => 'chargeback', 'amount' => '5', 'order' => 'SMB-NOSUCHID'], 'order'],
    ];
    foreach ($bad as [$in, $field]) {
        $e = T::throws(HttpException::class, fn() => Finance::addExpense($in, fn_req(), FN_ADMIN), 422);
        T::true(isset($e->extra['fields'][$field]), $field . ': ' . json_encode($e->extra));
    }
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM expenses'));
    Analytics::rollup($today, $today);
    $tools = Finance::addExpense(['day' => $today, 'category' => 'tools', 'amount' => '499', 'description' => 'SMS panel', 'reference' => 'INV-77'], fn_req(), FN_ADMIN);
    $cb = Finance::addExpense(['day' => $today, 'category' => 'chargeback', 'amount' => '5', 'order' => strtolower((string) $o['public_id'])], fn_req(), FN_ADMIN);
    T::same([49900, 'tools', 'SMS panel', 'INV-77', false], [$tools['amount_paise'], $tools['category'], $tools['description'], $tools['reference'], $tools['voided']]);
    T::same($o['public_id'], $cb['order'], 'the chargeback names its order (case-insensitive)');
    T::same(2, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.expense_added'"));
    $d = Analytics::computeDays($today, $today)[$today];
    T::same([500, 49900], [$d['chargebacks_paise'], $d['expenses_paise']]);
    // void: needs a reason, keeps the row, leaves the numbers
    T::throws(HttpException::class, fn() => Finance::voidExpense($tools['id'], '', fn_req(), FN_ADMIN), 422);
    $v = Finance::voidExpense($tools['id'], 'Entered twice', fn_req(), FN_ADMIN);
    T::same([true, 'Entered twice', 'root'], [$v['voided'], $v['void_reason'], $v['voided_by']]);
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM expenses WHERE id = ? AND voided_at IS NOT NULL', [$tools['id']]), 'the row is still there');
    T::same([500, 0], [Analytics::computeDays($today, $today)[$today]['chargebacks_paise'], Analytics::computeDays($today, $today)[$today]['expenses_paise']], 'a voided expense is not counted');
    T::throws(HttpException::class, fn() => Finance::voidExpense($tools['id'], 'again', fn_req(), FN_ADMIN), 409);
    T::throws(HttpException::class, fn() => Finance::voidExpense(99999, 'none', fn_req(), FN_ADMIN), 404);
    // the stored day is recomputed
    T::same(1, (int) Db::val('SELECT dirty FROM analytics_daily WHERE day = ?', [$today]));
    $list = Finance::expenses(['from' => $today, 'to' => $today]);
    T::same([1, 500], [$list['total'], $list['sum_paise']], 'the list hides voided entries and sums what counts');
    T::same(2, Finance::expenses(['include_voided' => true])['total']);
    T::same(1, Finance::expenses(['category' => 'chargeback'])['total']);
});

// ═════════════════════════════ the daily numbers ═════════════════════════════

/** One business day with every kind of cost, with exact expected figures. @return string the day */
function fn_day_with_everything(): string
{
    an_reset();
    $day = Tz::today();
    $line = static function (int $orderId, string $type, int $paise, bool $estimate = true) use ($day): void {
        Db::insert('order_costs', ['order_id' => $orderId, 'cost_type' => $type, 'amount_paise' => $paise, 'source' => 'admin', 'is_estimate' => $estimate ? 1 : 0, 'effective_at' => Clock::sql(), 'created_at' => Clock::sql(),
            'active_key' => in_array($type, ['fulfilment', 'payment_fee'], true) ? $orderId . ':' . $type : null]);
    };
    $auto = an_order('AUTO', 10000, 3000);                       // ₹100, provider ₹30
    Db::update('orders', ['provider_cost_final' => 1], 'id = ?', [$auto]);
    $line($auto, 'payment_fee', 200);
    $manual = an_order('MANUAL', 10000, 0, null, 1000, 'MANUAL_APPROVED');   // ₹100, ₹10 refunded
    $line($manual, 'fulfilment', 1500, false);
    $line($manual, 'payment_fee', 200);
    $line($manual, 'other', 300);
    an_visitors(10, 10, $day, 108);                              // CPC estimate ₹10.80
    Finance::addExpense(['day' => $day, 'category' => 'tools', 'amount' => '5'], fn_req(), FN_ADMIN);
    Finance::addExpense(['day' => $day, 'category' => 'chargeback', 'amount' => '7', 'order' => (string) Db::val('SELECT public_id FROM orders WHERE id = ?', [$manual])], fn_req(), FN_ADMIN);
    return $day;
}

T::test('daily numbers: provider, fulfilment, fee, other, chargebacks, expenses and advertising are each subtracted exactly once', function () {
    $day = fn_day_with_everything();
    $d = Analytics::computeDays($day, $day)[$day];
    T::same(10000 + 9000, $d['revenue_paise'], 'collected minus refunds');
    T::same(3000, $d['smm_spend_paise']);
    T::same([1500, 400, 300], [$d['fulfil_cost_paise'], $d['payment_fee_paise'], $d['other_cost_paise']]);
    T::same(19000 - 3000 - 2200, $d['gross_profit_paise'], 'contribution = revenue − provider − the order cost lines');
    T::same([700, 500], [$d['chargebacks_paise'], $d['expenses_paise']]);
    T::same([1080, 0, 1080, 0], [$d['ads_spend_paise'], $d['ads_actual_paise'], $d['ads_estimated_paise'], $d['ads_actual_day']], 'no actual spend: the estimate is the day\'s advertising');
    T::same(13800 - 700 - 500 - 1080, $d['net_profit_paise']);
    T::same(10000 - 3000 - 200, $d['auto_profit_paise'], 'AUTO profit carries its fee');
    T::same(9000 - 2000, $d['manual_profit_paise'], 'MANUAL profit carries fulfilment, fee and other — it is not 100 %');
    T::same([2, 0, 2], [$d['orders_paid'], $d['orders_cost_incomplete'], $d['orders_cost_estimated']], 'both orders have every cost line, none is incomplete; both carry an estimated fee line');
    // the stored copy and the live computation are the same numbers
    T::same(Analytics::computeDays($day, $day), Analytics::rollup($day, $day), 'rollup == direct');
    $past = Tz::addDays($day, -1);
    T::same(Analytics::computeDays($past, $day), Analytics::rollup($past, $day));
    // the dashboard shows the same net profit as the day
    $dash = Dashboard::build(['range' => 'today']);
    T::same(11520, $dash['kpis']['today']['profit']['value']);
    T::same(['revenue' => 19000, 'smm' => 3000, 'ads' => 1080, 'gross' => 13800, 'net' => 11520, 'margin_tenths' => 606, 'margin' => '60.6', 'other_costs' => 2200, 'overheads' => 1200], $dash['breakdown']);
    // the Finance overview carries the same figures, itemised
    $f = FinanceReport::overview(['range' => 'today']);
    $m = $f['period'];
    T::same([20000, 1000, 700, 18300], [$m['collected'], $m['refunds'], $m['chargebacks'], $m['net_revenue']]);
    T::same([3000, 1500, 400, 300, 5200], [$m['provider_cost'], $m['fulfilment'], $m['payment_fees'], $m['other_costs'], $m['order_costs']], 'order_costs = provider + fulfilment + fees + other');
    T::same([13800, 1080, 500, 11520], [$m['contribution'], $m['advertising'], $m['expenses'], $m['net_profit']]);
    T::same(Money_pct(11520, 18300), $m['margin_tenths']);
    T::same(10000, $m['aov'], 'collected ÷ paid orders');
    T::same($m, $f['today']);
    T::same($m, $f['lifetime']);
});

function Money_pct(int $n, int $d): int { return \App\Support\Money::pctTenths($n, $d); }

T::test('daily numbers: ACTUAL ad spend replaces the CPC estimate for its day — never both — and a voided entry brings the estimate back', function () {
    $day = fn_day_with_everything();
    $yesterday = Tz::addDays($day, -1);
    an_visitors(5, 5, $yesterday, 100);                                  // yesterday: estimate ₹5.00
    T::same(1080, Analytics::computeDays($day, $day)[$day]['ads_spend_paise']);
    $e = AdSpend::save(['day' => $day, 'amount' => '40', 'gst' => '7.20', 'campaign' => 'Diwali Sale', 'platform' => 'Meta'], fn_req(), FN_ADMIN);
    T::same(['diwali sale', 'meta', 4000, 720], [$e['campaign'], $e['platform'], $e['amount_paise'], $e['gst_paise']], 'names are cleaned the way the visitor tracker cleans utm_campaign');
    $d = Analytics::computeDays($day, $day)[$day];
    T::same([4720, 4720, 1080, 1], [$d['ads_spend_paise'], $d['ads_actual_paise'], $d['ads_estimated_paise'], $d['ads_actual_day']], 'the day carries the actual spend (GST counted as a cost), the estimate is only kept for reference');
    T::same(13800 - 700 - 500 - 4720, $d['net_profit_paise'], 'net profit subtracts the actual spend, not actual + estimate');
    T::same(500, Analytics::computeDays($yesterday, $yesterday)[$yesterday]['ads_spend_paise'], 'a day without actual spend keeps its estimate');
    // the GST treatment is a setting
    Finance::saveSettings(['fee_mode' => 'unset', 'ad_gst' => 'recoverable'], fn_req(), FN_ADMIN);
    T::same(4000, Analytics::computeDays($day, $day)[$day]['ads_spend_paise'], 'recoverable GST is not a cost');
    Finance::saveSettings(['fee_mode' => 'unset', 'ad_gst' => 'cost'], fn_req(), FN_ADMIN);
    // one campaign entered → the whole day is "actual"; the estimate for the other visitors of that day is NOT added back
    AdSpend::save(['day' => $day, 'amount' => '10', 'campaign' => 'other'], fn_req(), FN_ADMIN);
    T::same(4720 + 1000, Analytics::computeDays($day, $day)[$day]['ads_spend_paise']);
    // void both → the estimate returns
    AdSpend::void($e['id'], 'entered on the wrong day', fn_req(), FN_ADMIN);
    $other = (int) Db::val("SELECT id FROM ad_daily_spend WHERE campaign = 'other'");
    AdSpend::void($other, 'also wrong', fn_req(), FN_ADMIN);
    $d = Analytics::computeDays($day, $day)[$day];
    T::same([1080, 0, 0], [$d['ads_spend_paise'], $d['ads_actual_paise'], $d['ads_actual_day']], 'no live actual entry: the estimate stands again');
    // the stored copy never lags behind an entry
    Analytics::rollup($day, $day);
    AdSpend::save(['day' => $day, 'amount' => '1'], fn_req(), FN_ADMIN);
    T::same(1, (int) Db::val('SELECT dirty FROM analytics_daily WHERE day = ?', [$day]));
    T::same(100, Analytics::rollup($day, $day)[$day]['ads_spend_paise']);
});

T::test('filters: a platform / service / order-type view leaves out the costs that belong to the whole business', function () {
    $day = fn_day_with_everything();
    $all = FinanceReport::overview(['range' => 'today']);
    T::true($all['meta']['shared_costs_included'] && !$all['meta']['filtered']);
    $man = FinanceReport::overview(['range' => 'today', 'mode' => 'MANUAL']);
    T::true($man['meta']['filtered'] && !$man['meta']['shared_costs_included']);
    $m = $man['period'];
    T::same([10000, 1000, 0, 9000], [$m['collected'], $m['refunds'], $m['chargebacks'], $m['net_revenue']], 'the manual order only; the chargeback belongs to the whole business');
    T::same([1500, 200, 300], [$m['fulfilment'], $m['payment_fees'], $m['other_costs']], 'the manual order\'s own costs');
    T::same(7000, $m['contribution']);
    T::same(7000, $m['net_profit'], 'filtered: net = contribution (no advertising, chargebacks or expenses)');
    T::same(0, $m['expenses'], 'business expenses are not allocated to an order type');
    T::contains('Filtered view', implode(' ', $man['labels']['reasons']));
    $svc = FinanceReport::overview(['range' => 'today', 'service' => 'ig-followers-50'])['period'];
    T::same([20000, 13800], [$svc['collected'], $svc['contribution']], 'both synthetic orders are of that service');
    $ig = FinanceReport::overview(['range' => 'today', 'platform' => 'instagram'])['period'];
    T::same($all['period']['contribution'], $ig['contribution']);
    T::throws(HttpException::class, fn() => FinanceReport::overview(['range' => 'today', 'platform' => 'tiktok']), 422);
    T::throws(HttpException::class, fn() => FinanceReport::overview(['range' => 'today', 'service' => 'no-such-service']), 422);
    T::same(0, FinanceReport::overview(['range' => 'today', 'platform' => 'youtube'])['period']['collected'], 'nothing sold on YouTube');
    T::same($all['period']['net_profit'], FinanceReport::overview(['range' => 'today'])['today']['net_profit'], 'the unfiltered cards are the whole business');
});

T::test('labels: a profit figure says why it is incomplete, estimated or actual', function () {
    an_reset();
    $day = Tz::today();
    $auto = an_order('AUTO', 10000, 3000);
    Db::update('orders', ['provider_cost_final' => 1, 'provider_order_id' => 'P1'], 'id = ?', [$auto]);
    fn_fee('none');
    $l = FinanceReport::overview(['range' => 'today'])['labels'];
    T::same(['actual', 'none'], [$l['status'], $l['ads']], 'a final provider cost and no fee: everything is actual');
    T::same([], $l['reasons']);
    $est = an_order('AUTO', 10000, 3000);
    Db::update('orders', ['provider_order_id' => 'P2'], 'id = ?', [$est]);
    $l = FinanceReport::overview(['range' => 'today'])['labels'];
    T::same(['estimated', 1], [$l['status'], $l['orders']['estimated']]);
    T::contains('estimated cost', implode(' ', $l['reasons']));
    $man = an_order('MANUAL', 4900, 0, null, 0, 'MANUAL_PENDING');
    $l = FinanceReport::overview(['range' => 'today'])['labels'];
    T::same(['incomplete', 1, 1, 1], [$l['status'], $l['orders']['incomplete'], $l['orders']['estimated'], $l['orders']['actual']]);
    T::contains('1 of 3 paid order', implode(' ', $l['reasons']));
    T::contains('not known yet', implode(' ', $l['reasons']));
    // advertising: the estimate is named as an estimate, actual spend as actual
    an_visitors(4, 4, $day, 108);
    $l = FinanceReport::overview(['range' => 'today'])['labels'];
    T::same('estimated', $l['ads']);
    T::contains('CPC estimate', implode(' ', $l['reasons']));
    AdSpend::save(['day' => $day, 'amount' => '5'], fn_req(), FN_ADMIN);
    T::same('actual', FinanceReport::overview(['range' => 'today'])['labels']['ads']);
    Finance::recordLine($man, 'fulfilment', '10', 'standard', false, fn_req(), FN_ADMIN);
    $l = FinanceReport::overview(['range' => 'today'])['labels'];
    T::same(['estimated', 0], [$l['status'], $l['orders']['incomplete']], 'recording the missing cost removes the "incomplete" label');
});

// ═════════════════════════════ services ═════════════════════════════

T::test('services: contribution and margin per service, the most profitable, the loss-making and those with missing cost data', function () {
    an_reset();
    $day = Tz::today();
    fn_fee('none');
    $mk = static function (string $code, string $mode, int $price, int $cost, bool $final): int {
        $id = an_order($mode, $price, $cost, null, 0, 'COMPLETED', $code);
        Db::update('orders', ['provider_cost_final' => $final ? 1 : 0, 'provider_order_id' => $mode === 'AUTO' ? 'P' . $id : null], 'id = ?', [$id]);
        return $id;
    };
    Db::exec("UPDATE services SET mode = 'AUTO', smm_cost_paise = 2000 WHERE code = 'ig-followers-50'");
    Db::exec("UPDATE services SET mode = 'AUTO', smm_cost_paise = 900 WHERE code = 'ig-likes-100'");
    Db::exec("UPDATE services SET mode = 'AUTO', smm_cost_paise = 0 WHERE code = 'yt-likes-50k'");           // an AUTO service nobody gave a cost
    Db::exec("UPDATE services SET fulfil_cost_paise = NULL WHERE code = 'yt-views-10k'");                    // a MANUAL service with no cost stated
    $mk('ig-followers-50', 'AUTO', 5000, 2000, true);
    $mk('ig-followers-50', 'AUTO', 5000, 2000, true);                  // contribution 6000 on ₹100 → 60 %
    $mk('ig-likes-100', 'AUTO', 1000, 1500, true);                     // sold below cost: −500
    $mk('yt-likes-50k', 'AUTO', 3000, 0, false);                       // cost unknown
    $manual = $mk('yt-views-10k', 'MANUAL', 2500, 0, true);
    $r = FinanceReport::services($day, $day);
    $by = array_column($r['rows'], null, 'code');
    T::same([2, 10000, 4000, 6000, 600], [$by['ig-followers-50']['orders'], $by['ig-followers-50']['collected'], $by['ig-followers-50']['provider_cost'], $by['ig-followers-50']['contribution'], $by['ig-followers-50']['margin_tenths']]);
    T::same('actual', $by['ig-followers-50']['status']);
    T::same(-500, $by['ig-likes-100']['contribution'], 'sold below cost');
    T::same(['ig-followers-50', 'yt-likes-50k', 'yt-views-10k'], array_column($r['most_profitable'], 'code'), 'best contribution first — including the two whose cost is unknown, which carry their status');
    T::same(['actual', 'incomplete', 'incomplete'], array_column($r['most_profitable'], 'status'));
    T::same(['ig-likes-100'], array_column($r['loss_making'], 'code'));
    T::same('incomplete', $by['yt-likes-50k']['status']);
    T::same('incomplete', $by['yt-views-10k']['status'], 'a manual service with no cost stated');
    $missing = array_column($r['missing_cost'], null, 'code');
    T::true(isset($missing['yt-likes-50k']) && isset($missing['yt-views-10k']), 'both are listed: ' . implode(',', array_keys($missing)));
    T::contains('unknown cost', $missing['yt-likes-50k']['why']);
    T::true(!isset($missing['ig-followers-50']), 'a service with known costs is not listed');
    // a configured service that has not sold in the period is listed too (so its cost can be fixed before the first sale)
    Db::exec("UPDATE services SET mode = 'AUTO', smm_cost_paise = 0, status = 'active' WHERE code = 'yt-subs-50'");
    $codes = array_column(FinanceReport::services($day, $day)['missing_cost'], 'code');
    T::true(in_array('yt-subs-50', $codes, true), 'an AUTO service with no cost, even with no sales');
    // filtering by order type narrows the services table too
    T::same(['yt-views-10k'], array_column(FinanceReport::services($day, $day, null, 'MANUAL')['rows'], 'code'));
    T::true($manual > 0);
});

// ═════════════════════════════ reconciliation ═════════════════════════════

T::test('reconciliation: finds missing and inconsistent money and offers only append-only fixes (dry run first, idempotent)', function () {
    an_reset();
    $day = Tz::today();
    $o1 = an_order('MANUAL', 5000, 0, null, 0, 'MANUAL_APPROVED');
    $o2 = an_order('MANUAL', 4000, 0, null, 0, 'MANUAL_APPROVED', 'ig-likes-100');
    $auto = an_order('AUTO', 5000, 0, null, 0, 'SUBMITTED');
    Db::update('orders', ['provider_order_id' => 'P9', 'provider_cost_final' => 0], 'id = ?', [$auto]);
    $keys = static fn(array $r): array => array_column($r['issues'], 'key');
    $r = FinanceReport::reconciliation();
    T::true(in_array('fee_missing', $keys($r), true), 'the fee model is unset: no order has a fee line');
    T::true(in_array('fulfilment_missing', $keys($r), true) && in_array('provider_cost_unknown', $keys($r), true));
    $fee = array_column($r['issues'], null, 'key')['fee_missing'];
    T::same(3, $fee['count']);
    T::same(null, $fee['fix'], 'no fix while the model is not set up');
    T::contains('not set up', $fee['text']);
    T::true(count($fee['sample']) === 3 && isset($fee['sample'][0]['public_id']));
    // fee model → a fix appears; the dry run changes nothing
    fn_fee('percent', '2');
    $fee = array_column(FinanceReport::reconciliation()['issues'], null, 'key')['fee_missing'];
    T::same('backfill_fees', $fee['fix']['action']);
    $dry = FinanceReport::fix('backfill_fees', true, fn_req(), FN_ADMIN);
    T::same([true, 3, 100 + 80 + 100], [$dry['dry_run'], $dry['orders'], $dry['amount_paise']]);
    T::contains('Nothing was changed', $dry['message']);
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM order_costs'));
    $real = FinanceReport::fix('backfill_fees', false, fn_req(), FN_ADMIN);
    T::same([false, 3], [$real['dry_run'], $real['orders']]);
    T::same(3, (int) Db::val("SELECT COUNT(*) FROM order_costs WHERE cost_type = 'payment_fee' AND source = 'rate' AND is_estimate = 1"));
    T::same(0, FinanceReport::fix('backfill_fees', false, fn_req(), FN_ADMIN)['orders'], 'idempotent: nothing is added twice');
    T::same(1, (int) Db::val('SELECT dirty FROM analytics_daily WHERE day = ?', [$day]));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.reconcile_fix'"), 'only the real run is audited');
    T::false(in_array('fee_missing', $keys(FinanceReport::reconciliation()), true));
    T::throws(HttpException::class, fn() => FinanceReport::fix('delete_everything', false, fn_req(), FN_ADMIN), 422);
    fn_fee('unset');
    T::throws(HttpException::class, fn() => FinanceReport::fix('backfill_fees', false, fn_req(), FN_ADMIN), 409);
    // a standard fulfilment cost set later can be applied to the orders that were paid before it existed
    $ful = array_column(FinanceReport::reconciliation()['issues'], null, 'key')['fulfilment_missing'];
    T::same(null, $ful['fix'], 'no service has a standard cost yet');
    Db::exec("UPDATE services SET fulfil_cost_paise = 700 WHERE code = 'ig-followers-50'");
    $ful = array_column(FinanceReport::reconciliation()['issues'], null, 'key')['fulfilment_missing'];
    T::same('backfill_fulfilment', $ful['fix']['action']);
    $done = FinanceReport::fix('backfill_fulfilment', false, fn_req(), FN_ADMIN);
    T::same([1, 700], [$done['orders'], $done['amount_paise']], 'only the order of the service that has a standard cost');
    $fulLine = array_values(array_filter(fn_lines($o1), fn($l) => $l['cost_type'] === 'fulfilment'));
    T::same([1, 700, 'service_default'], [count($fulLine), (int) $fulLine[0]['amount_paise'], $fulLine[0]['source']]);
    T::same(0, count(array_filter(fn_lines($o2), fn($l) => $l['cost_type'] === 'fulfilment')), 'the order of the service without a standard cost got nothing');
});

T::test('reconciliation: revenue without a verified payment, refund records that disagree and loss-making orders are reported', function () {
    an_reset();
    fn_fee('none');
    $keys = static fn(): array => array_column(FinanceReport::reconciliation()['issues'], null, 'key');
    $good = an_order('AUTO', 5000, 1000);
    Db::update('orders', ['provider_cost_final' => 1, 'provider_order_id' => 'P1'], 'id = ?', [$good]);
    Db::insert('order_costs', ['order_id' => $good, 'cost_type' => 'fulfilment', 'amount_paise' => 0, 'source' => 'admin', 'is_estimate' => 0, 'effective_at' => Clock::sql(), 'created_at' => Clock::sql(), 'active_key' => $good . ':fulfilment']);
    T::same(['loss_making_orders' => false], ['loss_making_orders' => isset($keys()['loss_making_orders'])]);
    // an order counted as paid whose payment was never verified
    $ghost = an_order('MANUAL', 3000, 0, null, 0, 'MANUAL_PENDING');
    Db::exec('DELETE FROM payments WHERE order_id = ?', [$ghost]);
    T::same(1, $keys()['revenue_unverified']['count']);
    T::same('high', $keys()['revenue_unverified']['severity']);
    // a verified payment whose order does not count as paid
    $p = an_order('MANUAL', 3500, 0, null, 0, 'PENDING_PAYMENT');
    Db::update('orders', ['paid_at' => null, 'paid_day' => null], 'id = ?', [$p]);
    T::same(1, $keys()['payment_without_order_paid']['count']);
    // a refunded order with no refunded amount, and a refunded amount above the payment
    $r1 = an_order('MANUAL', 2000, 0, null, 0, 'REFUNDED');
    $r2 = an_order('MANUAL', 2000, 0, null, 0, 'COMPLETED');
    Db::exec('UPDATE orders SET refunded_paise = 2500 WHERE id = ?', [$r2]);
    T::same(2, $keys()['refund_mismatch']['count']);
    // an order that lost money
    $loss = an_order('AUTO', 1000, 1500);
    Db::update('orders', ['provider_cost_final' => 1, 'provider_order_id' => 'P2'], 'id = ?', [$loss]);
    $l = $keys()['loss_making_orders'];
    T::true($l['count'] >= 1 && $l['severity'] === 'info');
    T::true(in_array($loss, array_column($l['sample'], 'id'), true));
    // advertising estimated for days nobody entered spend for
    an_visitors(3, 3, Tz::addDays(Tz::today(), -3), 108);
    T::contains('estimated for 1 of the last 30 days', $keys()['ads_estimated_days']['title']);
    AdSpend::save(['day' => Tz::addDays(Tz::today(), -3), 'amount' => '2'], fn_req(), FN_ADMIN);
    T::true(!isset($keys()['ads_estimated_days']), 'entering the spend closes the issue');
});

// ═════════════════════════════ one order's money ═════════════════════════════

T::test('order view: lines with their source and time, the contribution, and the status with its reasons', function () {
    pf_setup();
    fn_fee('percent', '2');
    Db::exec("UPDATE services SET fulfil_cost_paise = 120 WHERE code = 'ig-followers-50'");
    $o = fn_paid();
    $v = Finance::orderView((int) $o['id']);
    T::same([true, 'MANUAL', 500, 0, 0, 130, 370], [$v['paid'], $v['mode'], $v['collected_paise'], $v['refunded_paise'], $v['provider_cost_paise'], $v['line_costs_paise'], $v['contribution_paise']]);
    T::same('estimated', $v['cost_status']);
    T::same(['payment_fee', 'fulfilment'], array_column($v['lines'], 'type'));
    T::same(['the fee model', "the service’s standard cost"], array_column($v['lines'], 'source_label'));
    T::contains('2% of the payment', $v['fee_model']);
    T::contains('estimate', implode(' ', $v['reasons']));
    $unpaid = pf_place('yt-likes-50k', 'https://www.youtube.com/watch?v=abcdefghijk', ['visitor_id' => null, 'session_id' => null], ff_ip());
    $u = Finance::orderView((int) $unpaid['order']['id']);
    T::same([false, null, null], [$u['paid'], $u['contribution_paise'], $u['cost_status']]);
    T::throws(HttpException::class, fn() => Finance::orderView(999999), 404);
});

// ═════════════════════════════ actual ad spend ═════════════════════════════

T::test('ad spend: validated, saved per day / campaign / platform, replaced by the same key (old value audited), voided and re-entered', function () {
    an_reset();
    $today = Tz::today();
    $bad = [
        [['day' => Tz::addDays($today, 1), 'amount' => '5'], 'day'], [['day' => 'yesterday', 'amount' => '5'], 'day'], [['day' => '2019-12-31', 'amount' => '5'], 'day'],
        [['day' => $today], 'amount'], [['day' => $today, 'amount' => '-1'], 'amount'], [['day' => $today, 'amount' => '1.999'], 'amount'], [['day' => $today, 'amount' => '99999999999'], 'amount'],
        [['day' => $today, 'amount' => '5', 'gst' => 'x'], 'gst'], [['day' => $today, 'amount' => '5', 'clicks' => '-2'], 'clicks'], [['day' => $today, 'amount' => '5', 'clicks' => '1.5'], 'clicks'],
        [['day' => $today, 'amount' => '5', 'clicks' => '50', 'impressions' => '10'], 'clicks'],
    ];
    foreach ($bad as [$in, $field]) {
        $e = T::throws(HttpException::class, fn() => AdSpend::save($in, fn_req(), FN_ADMIN), 422);
        T::true(isset($e->extra['fields'][$field]), $field . ': ' . json_encode($e->extra));
    }
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM ad_daily_spend'));
    $a = AdSpend::save(['day' => $today, 'amount' => '0', 'note' => 'paused all day'], fn_req(), FN_ADMIN);
    T::same([0, '', '', false], [$a['amount_paise'], $a['campaign'], $a['platform'], $a['voided']], '₹0 is a valid entry: nothing was spent');
    T::same(0, Analytics::computeDays($today, $today)[$today]['ads_spend_paise']);
    T::same(1, Analytics::computeDays($today, $today)[$today]['ads_actual_day'], 'and it replaces any estimate for that day');
    $b = AdSpend::save(['day' => $today, 'amount' => '12.50', 'clicks' => '100', 'impressions' => '4000'], fn_req(), FN_ADMIN);
    T::same([$a['id'], 1250, 100, 4000, 'manual'], [$b['id'], $b['amount_paise'], $b['clicks'], $b['impressions'], $b['source']], 'the same day / campaign / platform is one entry: it is replaced');
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM ad_daily_spend'));
    $changed = Db::one("SELECT meta_json FROM audit_logs WHERE action = 'ads.spend_changed'");
    T::contains('"amount":"0.00"', (string) $changed['meta_json'], 'what it replaced is in the audit log');
    $v = AdSpend::void($b['id'], 'not ours', fn_req(), FN_ADMIN);
    T::true($v['voided']);
    T::throws(HttpException::class, fn() => AdSpend::void($b['id'], 'again', fn_req(), FN_ADMIN), 409);
    T::throws(HttpException::class, fn() => AdSpend::void($b['id'], '', fn_req(), FN_ADMIN), 422);
    T::throws(HttpException::class, fn() => AdSpend::void(999, 'none', fn_req(), FN_ADMIN), 404);
    $re = AdSpend::save(['day' => $today, 'amount' => '3'], fn_req(), FN_ADMIN);
    T::same([$b['id'], false, 300], [$re['id'], $re['voided'], $re['amount_paise']], 're-entering a voided key brings it back');
    $list = AdSpend::entries(['from' => $today, 'to' => $today]);
    T::same([1, 300], [$list['total'], $list['sum_paise']]);
    T::same(0, AdSpend::entries(['campaign' => 'nothing'])['total']);
    $with = AdSpend::save(['day' => $today, 'amount' => '8', 'campaign' => 'summer', 'platform' => 'google'], fn_req(), FN_ADMIN);
    T::same(1, AdSpend::entries(['campaign' => 'summer'])['total']);
    T::same(1, AdSpend::entries(['campaign' => '(none)'])['total'], 'the label of the unnamed bucket is accepted back');
    T::same($with['id'] > $b['id'], true);
});

T::test('ad spend: a pasted report is validated line by line and saved all-or-nothing (dry run first)', function () {
    an_reset();
    $today = Tz::today();
    $d1 = Tz::addDays($today, -1);
    $d2 = Tz::addDays($today, -2);
    $csv = "date,campaign,platform,spend,gst,clicks,impressions\n{$d2},Diwali,meta,100.50,18.09,230,9000\n{$d1};Diwali;meta;120;21.60;250;9500\n{$d1}\tbrand\tgoogle\t40\t\t\t";
    $dry = AdSpend::import($csv, true, fn_req(), FN_ADMIN);
    T::same([3, 3, 0, true], [$dry['rows'], $dry['created'], $dry['changed'], $dry['dry_run']]);
    T::same(10050 + 1809 + 12000 + 2160 + 4000, $dry['total_paise']);
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM ad_daily_spend'), 'a dry run saves nothing');
    $real = AdSpend::import($csv, false, fn_req(), FN_ADMIN);
    T::same(3, $real['created']);
    $rows = Db::all('SELECT spend_day, campaign, platform, amount_paise, gst_paise, clicks, impressions, source FROM ad_daily_spend ORDER BY id');
    T::same([$d2, 'diwali', 'meta', '10050', '1809', '230', '9000', 'import'], array_values(array_map('strval', $rows[0])));
    T::same([$d1, 'brand', 'google', '4000', '0', null, null, 'import'], array_map(fn($v) => $v === null ? null : (string) $v, array_values($rows[2])), 'empty clicks and impressions stay unknown (NULL), not 0');
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'ads.spend_imported'"));
    // importing again replaces instead of duplicating
    $again = AdSpend::import($csv, false, fn_req(), FN_ADMIN);
    T::same([0, 3], [$again['created'], $again['changed']]);
    T::same(3, (int) Db::val('SELECT COUNT(*) FROM ad_daily_spend'));
    // one bad line rejects everything and names the line
    $before = (int) Db::val('SELECT SUM(amount_paise) FROM ad_daily_spend');
    $bad = "{$d2},Diwali,meta,100\nnot-a-date,x,y,5\n{$d1},Diwali,meta,abc";
    $e = T::throws(HttpException::class, fn() => AdSpend::import($bad, false, fn_req(), FN_ADMIN), 422);
    T::contains('Line 2', $e->getMessage());
    T::contains('Line 3', $e->getMessage());
    T::same($before, (int) Db::val('SELECT SUM(amount_paise) FROM ad_daily_spend'), 'nothing was changed');
    $e = T::throws(HttpException::class, fn() => AdSpend::import("{$d2},a,b,5\n{$d2},a,b,6", false, fn_req(), FN_ADMIN), 422);
    T::contains('appears on line 1', $e->getMessage());
    T::throws(HttpException::class, fn() => AdSpend::import('   ', false, fn_req(), FN_ADMIN), 422);
    T::throws(HttpException::class, fn() => AdSpend::import(implode("\n", array_fill(0, 501, "{$d2},a,b,5")), true, fn_req(), FN_ADMIN), 422);
});
