<?php
declare(strict_types=1);

use App\Controllers\Admin\AdsController;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Attribution;
use App\Support\Tz;

/**
 * Who may see and change advertising figures. Reading needs ads.view (owner, manager, analyst); entering or importing spend, voiding an entry, changing
 * the attribution window and attributing older orders need ads.manage (owner, manager). A refused call changes nothing and writes no audit row.
 */

function aa_req(string $method, string $path, string $role, array $body = [], array $query = [], array $headers = []): Request
{
    $req = Request::fake($method, $path, ['Content-Type' => 'application/json'] + $headers, $body ? json_encode($body) : '', [], '203.0.113.9', $query);
    $req->attributes['admin'] = ['id' => 1, 'username' => $role . '-user', 'email' => 'u@x.test', 'role' => $role, 'must_change_password' => false];
    return $req;
}

/** @return array<string,mixed> */
function aa_json(Response $res): array
{
    return json_decode((string) $res->body, true) ?: [];
}

T::test('ads access: ads.view roles read everything, only owners and managers write — and a refused call changes nothing', function () {
    ad_scenario();
    $today = Tz::today();
    $ctl = new AdsController();
    $reads = [
        'overview' => static fn(string $r): Response => $ctl->overview(aa_req('GET', '/admin/api/ads', $r, [], ['range' => 'today']), []),
        'spend' => static fn(string $r): Response => $ctl->spend(aa_req('GET', '/admin/api/ads/spend', $r), []),
        'settings' => static fn(string $r): Response => $ctl->settings(aa_req('GET', '/admin/api/ads/settings', $r), []),
    ];
    foreach ($reads as $name => $call) {
        foreach (['owner', 'manager', 'analyst'] as $role) {
            T::true(aa_json($call($role))['ok'], "$role reads $name");
        }
        $e = T::throws(HttpException::class, fn() => $call('support'), 403);
        T::same('forbidden', $e->errorCode, "support is refused $name");
    }
    T::same([true, true, false], [aa_json($reads['overview']('owner'))['can']['manage'], aa_json($reads['overview']('manager'))['can']['manage'], aa_json($reads['overview']('analyst'))['can']['manage']], 'the page is told what the role may do');
    $ov = aa_json($reads['overview']('analyst'))['ads'];
    T::same([4 * 108, 2], [$ov['cards']['advertising_used'], $ov['cards']['orders']], 'the analyst sees the real figures');
    T::true(count($ov['campaigns']) >= 3);

    $entry = ['day' => $today, 'campaign' => 'alpha', 'platform' => 'meta', 'amount' => '10', 'gst' => '1.80', 'clicks' => '20'];
    $writes = [
        'addSpend' => static fn(string $r): Response => $ctl->addSpend(aa_req('POST', '/admin/api/ads/spend', $r, $entry), []),
        'importSpend' => static fn(string $r): Response => $ctl->importSpend(aa_req('POST', '/admin/api/ads/spend/import', $r, ['csv' => "{$today},beta,meta,7.50"]), []),
        'voidSpend' => static fn(string $r): Response => $ctl->voidSpend(aa_req('POST', '/admin/api/ads/spend/1/void', $r, ['reason' => 'entered twice']), ['id' => '1']),
        'saveSettings' => static fn(string $r): Response => $ctl->saveSettings(aa_req('PUT', '/admin/api/ads/settings', $r, ['window_days' => 14]), []),
        'backfill' => static fn(string $r): Response => $ctl->backfill(aa_req('POST', '/admin/api/ads/attribution/backfill', $r, ['dry_run' => false]), []),
    ];
    $snapshot = static fn(): array => [
        (int) Db::val('SELECT COUNT(*) FROM ad_daily_spend'), Attribution::windowDays(), (int) Db::val('SELECT COUNT(*) FROM campaign_attribution'),
        (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'ads.%'"),
    ];
    Db::exec('DELETE FROM campaign_attribution WHERE order_id = (SELECT id FROM (SELECT MIN(id) AS id FROM campaign_attribution) x)');     // one order is waiting to be attributed
    $before = $snapshot();
    foreach (['analyst', 'support'] as $role) {
        foreach ($writes as $name => $call) {
            $e = T::throws(HttpException::class, fn() => $call($role), 403);
            T::same('forbidden', $e->errorCode, "$role is refused $name");
        }
    }
    T::same($before, $snapshot(), 'the refused calls changed nothing and wrote no audit row');

    foreach (['owner', 'manager'] as $role) {
        $added = aa_json($writes['addSpend']($role));
        T::same([true, 1000, 180, 'alpha'], [$added['ok'], $added['entry']['amount_paise'], $added['entry']['gst_paise'], $added['entry']['campaign']], "$role enters spend");
        T::true(aa_json($writes['importSpend']($role))['result']['rows'] === 1, "$role imports a report");
    }
    $id = (int) Db::val("SELECT id FROM ad_daily_spend WHERE campaign = 'alpha'");
    $void = static fn(string $role, array $h = []): Response => $ctl->voidSpend(aa_req('POST', "/admin/api/ads/spend/{$id}/void", $role, ['reason' => 'entered twice'], [], $h), ['id' => (string) $id]);
    T::same(true, aa_json($void('manager', ['Idempotency-Key' => 'ads-void-1']))['entry']['voided']);
    $again = $void('manager', ['Idempotency-Key' => 'ads-void-1']);
    T::same(['true'], array_values(array_filter([$again->headers['X-Idempotent-Replay'] ?? null])), 'a double click is a replay, not a second void or a 409');
    T::throws(HttpException::class, fn() => $void('manager'), 409);
    T::same(14, aa_json($writes['saveSettings']('manager'))['attribution']['window_days']);
    // attributing older orders: dry run first
    $dry = aa_json($ctl->backfill(aa_req('POST', '/admin/api/ads/attribution/backfill', 'manager', ['dry_run' => true]), []))['result'];
    T::same([true, 1, 'Would attribute 1 verified order. Nothing was changed.'], [$dry['dry_run'], $dry['orders'], $dry['message']]);
    T::same(1, Attribution::missing(), 'the dry run wrote nothing');
    $real = aa_json($writes['backfill']('owner'))['result'];
    T::same([false, 1, 0], [$real['dry_run'], $real['orders'], $real['remaining']]);
    T::same(0, Attribution::missing());
    foreach (['ads.spend_added', 'ads.spend_imported', 'ads.spend_voided', 'ads.attribution_changed', 'ads.attribution_backfilled'] as $action) {
        T::true((int) Db::val('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]) >= 1, "$action is audited");
    }
    // validation reaches the page as field errors, not a 500
    foreach ([['day' => $today, 'amount' => 'abc'], ['day' => 'x', 'amount' => '5'], ['day' => $today, 'amount' => '5', 'clicks' => '-1'], ['day' => Tz::addDays($today, 2), 'amount' => '5']] as $bad) {
        $e = T::throws(HttpException::class, fn() => $ctl->addSpend(aa_req('POST', '/admin/api/ads/spend', 'owner', $bad), []), 422);
        T::true(isset($e->extra['fields']) && $e->extra['fields'] !== [], json_encode($bad));
    }
    $e = T::throws(HttpException::class, fn() => $ctl->importSpend(aa_req('POST', '/admin/api/ads/spend/import', 'owner', ['csv' => "{$today},a,b,5\nnot-a-date,x,y,5"]), []), 422);
    T::contains('Line 2', (string) $e->extra['fields']['csv']);
    $e = T::throws(HttpException::class, fn() => $ctl->saveSettings(aa_req('PUT', '/admin/api/ads/settings', 'owner', ['window_days' => 400]), []), 422);
    T::true(isset($e->extra['fields']['window_days']));
    T::throws(HttpException::class, fn() => $ctl->voidSpend(aa_req('POST', '/admin/api/ads/spend/999999/void', 'owner', ['reason' => 'no such entry']), ['id' => '999999']), 404);
});

T::test('ads access: the spend list pages and filters, and shows who entered what', function () {
    an_reset();
    fn_seed_admin();
    $today = Tz::today();
    for ($i = 0; $i < 30; $i++) {
        App\Services\AdSpend::save(['day' => Tz::addDays($today, -$i), 'campaign' => $i % 2 ? 'odd' : 'even', 'platform' => 'meta', 'amount' => (string) (10 + $i)], fn_req(), FN_ADMIN);
    }
    $ctl = new AdsController();
    $page = static fn(array $q): array => aa_json($ctl->spend(aa_req('GET', '/admin/api/ads/spend', 'analyst', [], $q), []));
    $p1 = $page([]);
    T::same([30, 2, 25, 25], [$p1['total'], $p1['pages'], $p1['per'], count($p1['rows'])]);
    T::same(5, count($page(['page' => '2'])['rows']));
    T::same(15, $page(['campaign' => 'odd'])['total']);
    T::same(['even', 'odd'], $p1['campaigns']);
    T::same('root', $p1['rows'][0]['by']);
    $void = App\Services\AdSpend::void((int) $p1['rows'][0]['id'], 'wrong day', fn_req(), FN_ADMIN);
    T::same(29, $page([])['total'], 'a voided entry is left out…');
    T::same(30, $page(['include_voided' => '1'])['total'], '…unless asked for');
    T::same(true, $void['voided']);
});
