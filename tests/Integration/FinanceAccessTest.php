<?php
declare(strict_types=1);

use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\FinanceController;
use App\Controllers\Admin\OrdersController;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Analytics;
use App\Support\Tz;

/**
 * Who may see and change money. Profit, cost and advertising figures belong to roles with finance.view (owner, manager, analyst); recording a cost, an
 * expense or a setting needs finance.adjust (owner, manager). For the other roles the figures are REMOVED from the data the server sends — a hidden
 * column on the page would still be readable in the browser's network tab.
 */

function fa_req(string $method, string $path, string $role, array $body = [], array $query = []): Request
{
    $req = Request::fake($method, $path, ['Content-Type' => 'application/json'], $body ? json_encode($body) : '', [], '203.0.113.9', $query);
    $req->attributes['admin'] = ['id' => 1, 'username' => $role . '-user', 'email' => 'u@x.test', 'role' => $role, 'must_change_password' => false];
    return $req;
}

/** @return array<string,mixed> */
function fa_json(Response $res): array
{
    return json_decode((string) $res->body, true) ?: [];
}

/** Every key name anywhere in a decoded JSON document that holds a value (a key left as null carries nothing to read). @return list<string> */
function fa_keys(mixed $v): array
{
    $out = [];
    if (is_array($v)) {
        foreach ($v as $k => $child) {
            if (is_string($k) && $child !== null) {
                $out[] = $k;
            }
            $out = array_merge($out, fa_keys($child));
        }
    }
    return $out;
}

T::test('finance access: finance.view reads everything, only owners and managers change a figure — a refused call changes nothing', function () {
    pf_setup();
    fn_fee('none');
    $o = fn_paid();
    $oid = (string) $o['id'];
    $ctl = new FinanceController();

    $reads = [
        'overview' => static fn(string $r): Response => $ctl->overview(fa_req('GET', '/admin/api/finance', $r), []),
        'reconciliation' => static fn(string $r): Response => $ctl->reconciliation(fa_req('GET', '/admin/api/finance/reconciliation', $r), []),
        'order' => static fn(string $r): Response => $ctl->order(fa_req('GET', "/admin/api/finance/orders/{$oid}", $r), ['id' => $oid]),
        'expenses' => static fn(string $r): Response => $ctl->expenses(fa_req('GET', '/admin/api/finance/expenses', $r), []),
        'settings' => static fn(string $r): Response => $ctl->settings(fa_req('GET', '/admin/api/finance/settings', $r), []),
    ];
    foreach ($reads as $name => $call) {
        foreach (['owner', 'manager', 'analyst'] as $role) {
            T::true(fa_json($call($role))['ok'], "$role reads $name");
        }
        $e = T::throws(HttpException::class, fn() => $call('support'), 403);
        T::same('forbidden', $e->errorCode, "support is refused $name");
    }
    T::same([true, true, false], [fa_json($reads['overview']('owner'))['can']['adjust'], fa_json($reads['overview']('manager'))['can']['adjust'], fa_json($reads['overview']('analyst'))['can']['adjust']], 'the page is told what the role may do');

    $today = Tz::today();
    $writes = [
        'recordCost' => static fn(string $r): Response => $ctl->recordCost(fa_req('POST', "/admin/api/finance/orders/{$oid}/costs", $r, ['type' => 'other', 'amount' => '1', 'note' => 'packing material']), ['id' => $oid]),
        'voidCost' => static fn(string $r): Response => $ctl->voidCost(fa_req('POST', '/admin/api/finance/costs/1/void', $r, ['reason' => 'entered by mistake']), ['id' => '1']),
        'addExpense' => static fn(string $r): Response => $ctl->addExpense(fa_req('POST', '/admin/api/finance/expenses', $r, ['day' => $today, 'category' => 'tools', 'amount' => '5', 'description' => 'tool']), []),
        'voidExpense' => static fn(string $r): Response => $ctl->voidExpense(fa_req('POST', '/admin/api/finance/expenses/1/void', $r, ['reason' => 'entered twice']), ['id' => '1']),
        'saveSettings' => static fn(string $r): Response => $ctl->saveSettings(fa_req('PUT', '/admin/api/finance/settings', $r, ['fee_mode' => 'percent', 'fee_percent' => '2']), []),
        'fix' => static fn(string $r): Response => $ctl->fix(fa_req('POST', '/admin/api/finance/reconciliation/fix', $r, ['action' => 'backfill_fees']), []),
    ];
    $snapshot = static fn(): array => [
        (int) Db::val('SELECT COUNT(*) FROM order_costs'), (int) Db::val('SELECT COUNT(*) FROM expenses'),
        (string) Db::val("SELECT COALESCE(MAX(v), '') FROM admin_settings WHERE k = 'finance.fee_mode'"),
        (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'finance.%'"),
    ];
    $before = $snapshot();
    foreach (['analyst', 'support'] as $role) {
        foreach ($writes as $name => $call) {
            $e = T::throws(HttpException::class, fn() => $call($role), 403);
            T::same('forbidden', $e->errorCode, "$role is refused $name");
        }
    }
    T::same($before, $snapshot(), 'the refused calls changed nothing and wrote no audit row');

    foreach (['owner', 'manager'] as $role) {
        T::true(fa_json($writes['recordCost']($role))['ok'], "$role records a cost");
        T::true(fa_json($writes['addExpense']($role))['ok'], "$role records an expense");
        T::true(fa_json($writes['saveSettings']($role))['ok'], "$role saves the fee model");
        T::true(fa_json($writes['fix']($role))['ok'], "$role runs a reconciliation fix");
    }
    T::true(Db::val("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'finance.%'") > $before[3], 'what they did is audited');
});

T::test('finance access: the dashboard removes every profit, cost and advertising figure from the data for roles without finance.view', function () {
    an_reset();
    $today = Tz::today();
    an_order('AUTO', 10000, 4000);
    an_order('MANUAL', 5000, 0);
    Analytics::rollup($today, $today);
    $ctl = new DashboardController();
    $get = static fn(string $role): array => fa_json($ctl->data(fa_req('GET', '/admin/api/dashboard', $role, [], ['range' => '7d']), []))['dashboard'];

    $withMoney = ['gross_profit', 'net_profit', 'profit', 'smm', 'ads', 'smm_spend_paise', 'ads_spend_paise', 'net_profit_paise', 'gross_profit_paise', 'cpc_paise', 'chargebacks_paise', 'expenses_paise', 'fulfil_cost_paise', 'payment_fee_paise'];
    foreach (['owner', 'manager', 'analyst'] as $role) {
        $d = $get($role);
        T::true($d['meta']['finance'] ?? true, "$role sees the profit figures");
        T::true(is_array($d['breakdown']), "$role gets the profit card");
        T::true(array_intersect($withMoney, fa_keys($d)) !== [], "$role: the money keys are present (so the test below is not vacuous)");
    }
    foreach (['support'] as $role) {
        $d = $get($role);
        T::same(false, $d['meta']['finance'] ?? true, 'the page is told the figures are not for this role');
        T::same([], array_values(array_intersect($withMoney, fa_keys($d))), "$role: not one cost / profit / advertising key is in the response: " . implode(',', array_intersect($withMoney, fa_keys($d))));
        T::same([null, null, null], [$d['breakdown'], $d['labels'], $d['smm']]);
        T::true($d['kpis']['today']['revenue'] > 0 || $d['kpis']['lifetime']['revenue'] > 0, 'revenue and order counts are still there');
    }
});

T::test('finance access: the orders screens remove cost, profit and the provider\'s own answers for roles without finance.view, and say what the others may adjust', function () {
    pf_setup();
    fn_fee('none');
    $o = fn_paid();
    $oid = (string) $o['id'];
    $ctl = new OrdersController();
    $list = static fn(string $role): array => fa_json($ctl->list(fa_req('GET', '/admin/api/orders', $role), []));
    $show = static fn(string $role): array => fa_json($ctl->show(fa_req('GET', "/admin/api/orders/{$oid}", $role), ['id' => $oid]));

    $costKeys = ['cost_paise', 'cost_final', 'profit_paise', 'cost_status', 'estimated_cost_paise', 'line_costs'];
    foreach (['owner', 'manager', 'analyst'] as $role) {
        $row = $list($role)['rows'][0];
        T::true(array_key_exists('profit_paise', $row) && array_key_exists('cost_status', $row), "$role sees the profit and its status in the list");
        $m = $show($role)['detail']['money'] ?? $show($role)['money'] ?? null;
        T::true(is_array($m) && empty($m['restricted']), "$role gets the money block");
    }
    foreach (['support'] as $role) {
        $l = $list($role);
        T::same([], array_values(array_intersect($costKeys, fa_keys($l['rows']))), "$role: the list carries no cost or profit key");
        T::false($l['can']['finance']);
        $s = $show($role);
        T::same([], array_values(array_intersect($costKeys, fa_keys($s))), "$role: the order carries no cost or profit key");
        $money = $s['detail']['money'] ?? $s['money'];
        T::same(true, $money['restricted'], 'the money block says it is restricted');
        T::same([], array_values(array_intersect(['profit', 'gross', 'cost', 'finance', 'contribution'], array_keys($money))), 'and holds only what the customer paid');
    }
    // who may adjust: owner and manager yes, analyst (view only) no
    $adjust = static function (string $role) use ($show): bool {
        $s = $show($role);
        return (bool) (($s['detail']['money'] ?? $s['money'])['can_adjust'] ?? false);
    };
    T::same([true, true, false], [$adjust('owner'), $adjust('manager'), $adjust('analyst')]);
});
