<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Fulfilment;
use App\Services\Orders;
use App\Services\SmmProviders;
use App\Support\Clock;

$ADMIN = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test'];
function ff_req(): Request { return Request::fake('POST', '/admin/api/x'); }
function ff_ip(): string { static $n = 0; return '198.51.100.' . (++$n % 250 + 1); }

/** Fresh world with an AUTO service. @return array{gid:int,pid:int} */
function ff_setup(string $smmKey = 'smm-key-ok'): array
{
    $gid = pf_setup();
    $pid = T::configureSmm($smmKey);
    T::makeAuto('ig-followers-50', '1001', 100, $pid);
    return ['gid' => $gid, 'pid' => $pid];
}

/** Place + pay an order (webhook), running post-response tasks. @return array order row */
function ff_paid(int $gid, string $code = 'ig-followers-50', string $target = '@john'): array
{
    $p = pf_place($code, $target, ['visitor_id' => null, 'session_id' => null], ff_ip());
    pf_webhook($gid, pf_evt($p['payment']));
    return pf_order((int) $p['order']['id']);
}

/** Number of orders that actually EXIST at the (mock) provider. */
function ff_smm_orders(): int
{
    return count(T::smm('GET', '/__state')['orders']);
}

/** Point the provider at a closed port (connection refused = never reached the provider). */
function ff_provider_down(): void
{
    Db::exec("UPDATE smm_providers SET api_url = 'http://127.0.0.1:4099/api/v2'");
}

function ff_provider_up(): void
{
    Db::exec("UPDATE smm_providers SET api_url = 'http://127.0.0.1:4020/api/v2'");
}

function ff_smm_adds(): array
{
    return array_values(array_filter(T::smm('GET', '/__state')['requests'], fn($r) => $r['action'] === 'add'));
}

// ═════════════════════════════ AUTO ═════════════════════════════

T::test('auto: a paid AUTO order is sent to the provider exactly once with the right service / link / quantity', function () {
    ['gid' => $gid] = ff_setup();
    $o = ff_paid($gid, 'ig-followers-50', 'https://www.instagram.com/john/?igsh=x');
    T::same('SUBMITTED', $o['status']);
    T::same('70001', $o['provider_order_id']);
    T::same(100, (int) $o['provider_cost_paise'], 'estimate counted until the actual charge is known');
    T::same(0, (int) $o['provider_cost_final']);
    T::same(1, (int) $o['provider_attempts']);
    T::true($o['submitted_at'] !== null);
    $adds = ff_smm_adds();
    T::same(1, count($adds));
    T::same('1001', $adds[0]['service']);
    T::same('https://www.instagram.com/john/', $adds[0]['link']);
    T::same('50', $adds[0]['quantity']);
    $tx = Db::one("SELECT * FROM smm_transactions WHERE order_id = ? AND action = 'add'", [$o['id']]);
    T::same(1, (int) $tx['ok']);
    T::same('70001', $tx['provider_order_id']);
    T::false(str_contains((string) $tx['request_json'], 'smm-key-ok'), 'the API key is never stored in the transaction log');
    T::same(['PENDING_PAYMENT', 'PAID', 'PROCESSING', 'SUBMITTED'], Db::col('SELECT to_status FROM order_status_history WHERE order_id = ? ORDER BY id', [$o['id']]));
    // submitting again is a no-op: no second provider order
    T::same('skipped', Fulfilment::submit((int) $o['id'])['status']);
    T::same(1, count(ff_smm_adds()));
});

T::test('auto: status sync follows the provider and the ACTUAL charge replaces the estimate', function () {
    ['gid' => $gid] = ff_setup();
    $o = ff_paid($gid);
    T::smm('POST', '/__advance', ['order' => '70001', 'status' => 'In progress', 'remains' => 30]);
    T::true(Fulfilment::sync((int) $o['id'])['ok']);
    $o = pf_order((int) $o['id']);
    T::same('IN_PROGRESS', $o['status']);
    T::same(30, (int) $o['remains']);
    T::same(2323, (int) $o['provider_cost_paise'], '0.27819 USD × ₹83.5');
    T::same(0, (int) $o['provider_cost_final'], 'not final while in progress');

    T::smm('POST', '/__advance', ['order' => '70001', 'status' => 'Completed', 'remains' => 0, 'charge' => '0.30000']);
    Fulfilment::sync((int) $o['id']);
    $o = pf_order((int) $o['id']);
    T::same('COMPLETED', $o['status']);
    T::same(2505, (int) $o['provider_cost_paise']);
    T::same(1, (int) $o['provider_cost_final']);
    T::true($o['completed_at'] !== null);
});

T::test('auto: provider-cancelled orders cost nothing (the provider refunds us)', function () {
    ['gid' => $gid] = ff_setup();
    $o = ff_paid($gid);
    T::smm('POST', '/__advance', ['order' => '70001', 'status' => 'Canceled']);
    Fulfilment::sync((int) $o['id']);
    $o = pf_order((int) $o['id']);
    T::same('CANCELLED', $o['status']);
    T::same(0, (int) $o['provider_cost_paise']);
    T::same(1, (int) $o['provider_cost_final']);
});

T::test('auto: syncDue only touches live orders and respects the 2-minute spacing', function () {
    ['gid' => $gid] = ff_setup();
    Clock::setFixed(1_800_000_000);
    $o = ff_paid($gid);
    T::same(1, Fulfilment::syncDue()['synced']);
    T::same(0, Fulfilment::syncDue()['synced'], 'synced a moment ago');
    Clock::setFixed(1_800_000_200);
    T::smm('POST', '/__advance', ['order' => '70001', 'status' => 'Completed']);
    T::same(1, Fulfilment::syncDue()['synced']);
    T::same('COMPLETED', pf_order((int) $o['id'])['status']);
    Clock::setFixed(1_800_000_900);
    T::same(0, Fulfilment::syncDue()['synced'], 'completed orders are never polled again');
});

// ═════════════════════════════ AUTO failures (spec §58) ═════════════════════════════

T::test('auto failure: invalid API key → FAILED with the reason, nothing created, retry works after fixing', function () use ($ADMIN) {
    ['gid' => $gid, 'pid' => $pid] = ff_setup('WRONG-KEY');
    $o = ff_paid($gid);
    T::same('FAILED', $o['status']);
    T::same('rejected', $o['failure_kind']);
    T::contains('Invalid API key', (string) $o['last_error']);
    T::true($o['provider_order_id'] === null);
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM smm_transactions WHERE ok = 1 AND action = 'add'"));
    // admin fixes the key and retries
    Db::update('smm_providers', ['api_key_enc' => App\Core\Crypto::encrypt('smm-key-ok')], 'id = ?', [$pid]);
    T::same('submitted', Fulfilment::submit((int) $o['id'], $ADMIN, true)['status']);
    $o = pf_order((int) $o['id']);
    T::same('SUBMITTED', $o['status']);
    T::same(2, (int) $o['provider_attempts']);
    T::same(1, ff_smm_orders(), 'only one order exists at the provider');
    T::same(2, count(ff_smm_adds()), 'one rejected request + one accepted');
});

T::test('auto failure: insufficient provider balance → FAILED (rejected), admin can move it to manual', function () use ($ADMIN) {
    ['gid' => $gid] = ff_setup('smm-key-poor');
    $o = ff_paid($gid);
    T::same('FAILED', $o['status']);
    T::contains('Not enough funds', (string) $o['last_error']);
    Fulfilment::moveToManual((int) $o['id'], $ADMIN, ff_req());
    $o = pf_order((int) $o['id']);
    T::same('MANUAL_PENDING', $o['status']);
    T::same('MANUAL', $o['mode']);
    T::same(0, (int) $o['provider_cost_paise']);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM manual_orders WHERE order_id = ? AND state = 'pending'", [$o['id']]));
});

T::test('auto failure: invalid SMM service id → FAILED (rejected)', function () {
    ['gid' => $gid] = ff_setup();
    T::makeAuto('ig-followers-50', '9999', 100, (int) Db::val('SELECT id FROM smm_providers'));
    $o = ff_paid($gid);
    T::same('FAILED', $o['status']);
    T::contains('Incorrect service ID', (string) $o['last_error']);
});

T::test('auto failure: AUTO service without SMM service id / provider → FAILED (misconfigured), no call made', function () {
    ['gid' => $gid] = ff_setup();
    T::makeAuto('ig-followers-50', '', 100, null);
    Db::exec('DELETE FROM smm_providers');
    $o = ff_paid($gid);
    T::same('FAILED', $o['status']);
    T::same('misconfigured', $o['failure_kind']);
    T::same(0, count(ff_smm_adds()));
});

T::test('auto failure: provider OFFLINE → order stays PAID, retried with back-off, then succeeds — never duplicated', function () {
    ['gid' => $gid] = ff_setup();
    Clock::setFixed(1_800_000_000);
    ff_provider_down();
    $o = ff_paid($gid);
    T::same('PAID', $o['status'], 'customer already paid: the order waits, it does not fail');
    T::same('unreachable', $o['failure_kind']);
    T::same(1, (int) $o['provider_attempts']);
    T::same(Clock::sql(1_800_000_060), $o['next_attempt_at']);
    T::same(0, Fulfilment::submitDue()['attempted'], 'not due yet');
    ff_provider_up();
    Clock::setFixed(1_800_000_100);
    T::same(1, Fulfilment::submitDue()['attempted']);
    $o = pf_order((int) $o['id']);
    T::same('SUBMITTED', $o['status']);
    T::same(1, ff_smm_orders(), 'exactly one provider order');
    T::same(2, (int) $o['provider_attempts']);
});

T::test('auto failure: provider stays offline → 5 attempts, then FAILED for a human', function () {
    ['gid' => $gid] = ff_setup();
    Clock::setFixed(1_800_000_000);
    ff_provider_down();
    $o = ff_paid($gid);
    for ($i = 1; $i < 5; $i++) {
        Clock::setFixed(1_800_000_000 + $i * 10_000);
        Fulfilment::submitDue();
    }
    $o = pf_order((int) $o['id']);
    T::same('FAILED', $o['status']);
    T::same('unreachable', $o['failure_kind']);
    T::same(5, (int) $o['provider_attempts']);
    Clock::setFixed(1_800_100_000);
    T::same(0, Fulfilment::submitDue()['attempted'], 'FAILED orders are never retried automatically');
});

T::test('auto failure: connection DROPPED mid-request → AMBIGUOUS, parked, no blind retry (the provider may have the order)', function () {
    ['gid' => $gid] = ff_setup();
    T::smm('POST', '/__control', ['offline' => true]);     // mock accepts the request, then kills the socket
    $o = ff_paid($gid);
    T::same('FAILED', $o['status']);
    T::same('ambiguous', $o['failure_kind']);
    T::same(1, (int) $o['provider_attempts']);
    T::smm('POST', '/__control', ['offline' => false]);
    Clock::setFixed(Clock::now() + 86400);
    T::same(0, Fulfilment::submitDue()['attempted'], 'never auto-retried');
    T::same(0, ff_smm_orders(), 'no order exists at the provider, and none was created by a retry');
    T::same(1, (int) pf_order((int) $o['id'])['provider_attempts'], 'exactly one attempt was ever made');
});

T::test('auto failure: garbled reply → AMBIGUOUS: parked for a human, NEVER auto-retried (could double-order)', function () use ($ADMIN) {
    ['gid' => $gid] = ff_setup();
    T::smm('POST', '/__control', ['garbled' => true]);
    $o = ff_paid($gid);
    T::same('FAILED', $o['status']);
    T::same('ambiguous', $o['failure_kind']);
    T::contains('verify there', (string) $o['last_error']);
    Clock::setFixed(Clock::now() + 86400);
    T::same(0, Fulfilment::submitDue()['attempted']);
    T::smm('POST', '/__control', ['garbled' => false]);
    T::same('submitted', Fulfilment::submit((int) $o['id'], $ADMIN, true)['status'], 'an admin can retry deliberately');
});

T::test('auto: 6 parallel processes try to submit one PAID order — the provider receives ONE order', function () {
    ['gid' => $gid] = ff_setup();
    T::smm('POST', '/__control', ['offline' => false]);
    $p = pf_place('ig-followers-50', '@racer', ['visitor_id' => null, 'session_id' => null], ff_ip());
    // settle without the post-response submission so the order is PAID and unsent
    App\Services\Settlement::settle((int) $p['payment']['id'], ['source' => 'webhook', 'amount_paise' => 500, 'utr' => 'RACEUTR']);
    T::same('PAID', pf_order((int) $p['order']['id'])['status']);
    $go = sys_get_temp_dir() . '/smb-go2-' . getmypid();
    @unlink($go);
    $procs = [];
    $pipes = [];
    for ($i = 0; $i < 6; $i++) {
        $procs[] = proc_open(['php', SMB_ROOT . '/tests/fixtures/submit_worker.php', (string) $p['order']['id'], $go], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    usleep(800_000);
    touch($go);
    $out = [];
    foreach ($procs as $i => $proc) {
        $out[] = trim((string) stream_get_contents($pipes[$i][1]));
        proc_close($proc);
    }
    @unlink($go);
    $c = array_count_values($out);
    T::same(1, $c['"submitted"'] ?? 0, json_encode($c));
    T::same(5, $c['"skipped"'] ?? 0);
    T::same(1, count(ff_smm_adds()), 'the provider got exactly ONE add request');
    T::same('SUBMITTED', pf_order((int) $p['order']['id'])['status']);
});

// ═════════════════════════════ MANUAL queue ═════════════════════════════

T::test('manual: approve one order; approving twice or an unpaid order is refused', function () use ($ADMIN) {
    $gid = pf_setup();
    $o = ff_paid($gid, 'ig-likes-100', 'https://instagram.com/p/ABCDEFG/');
    T::same('MANUAL_PENDING', $o['status']);
    Fulfilment::approve((int) $o['id'], $ADMIN, ff_req(), 'delivered via panel X');
    $o2 = pf_order((int) $o['id']);
    T::same('MANUAL_APPROVED', $o2['status']);
    T::true($o2['completed_at'] !== null);
    T::same('approved', Db::val('SELECT state FROM manual_orders WHERE order_id = ?', [$o['id']]));
    T::throws(HttpException::class, fn() => Fulfilment::approve((int) $o['id'], $ADMIN, ff_req()), 409);

    $unpaid = pf_place('ig-likes-100', 'https://instagram.com/p/ABCDEFG/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    T::throws(HttpException::class, fn() => Fulfilment::approve((int) $unpaid['order']['id'], $ADMIN, ff_req()), 409);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.approved'"));
});

T::test('manual: APPROVE ALL approves only eligible orders (paid, manual, pending) and is repeatable', function () use ($ADMIN) {
    ['gid' => $gid] = ff_setup();
    $a = ff_paid($gid, 'ig-likes-100', 'https://instagram.com/p/AAAAAAA/');       // eligible
    $b = ff_paid($gid, 'ig-likes-50k', 'https://instagram.com/p/BBBBBBB/');       // eligible
    $c = pf_place('ig-likes-100', 'https://instagram.com/p/CCCCCCC/', ['visitor_id' => null, 'session_id' => null], ff_ip());   // UNPAID
    $d = ff_paid($gid, 'ig-views-1k', 'https://instagram.com/p/DDDDDDD/');
    Fulfilment::cancel((int) $d['id'], $ADMIN, ff_req(), 'customer asked');       // CANCELLED
    $e = ff_paid($gid, 'ig-views-1m', 'https://instagram.com/p/EEEEEEE/');
    Fulfilment::refund((int) $e['id'], 9900, 'UPI-REF-1', $ADMIN, ff_req());        // REFUNDED
    $f = ff_paid($gid, 'yt-likes-100', 'https://youtu.be/dQw4w9WgXcQ');
    Fulfilment::approve((int) $f['id'], $ADMIN, ff_req());                         // already approved
    $g = ff_paid($gid, 'ig-followers-50', '@auto_one');                            // AUTO, submitted to provider

    T::same(2, Fulfilment::approvableCount());
    $r = Fulfilment::approveAll($ADMIN, ff_req());
    T::same(2, $r['approved']);
    T::same('MANUAL_APPROVED', pf_order((int) $a['id'])['status']);
    T::same('MANUAL_APPROVED', pf_order((int) $b['id'])['status']);
    T::same('PENDING_PAYMENT', pf_order((int) $c['order']['id'])['status'], 'unpaid untouched');
    T::same('CANCELLED', pf_order((int) $d['id'])['status']);
    T::same('REFUNDED', pf_order((int) $e['id'])['status']);
    T::same('SUBMITTED', pf_order((int) $g['id'])['status'], 'AUTO orders are never approved by Approve All');
    T::same(0, Fulfilment::approveAll($ADMIN, ff_req())['approved'], 'nothing left to approve');
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.approve_all' AND meta_json LIKE '%\"approved\":2%'"));
});

T::test('manual: two concurrent APPROVE ALL runs approve each order exactly once in total', function () {
    $gid = pf_setup();
    for ($i = 0; $i < 6; $i++) {
        ff_paid($gid, 'ig-likes-100', 'https://instagram.com/p/ZZZZZ' . $i . '/');
    }
    $go = sys_get_temp_dir() . '/smb-go3-' . getmypid();
    @unlink($go);
    $procs = [];
    $pipes = [];
    for ($i = 0; $i < 3; $i++) {
        $procs[] = proc_open(['php', SMB_ROOT . '/tests/fixtures/submit_worker.php', '0', $go, 'approve_all'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    usleep(800_000);
    touch($go);
    $total = 0;
    foreach ($procs as $i => $proc) {
        $total += (int) trim((string) stream_get_contents($pipes[$i][1]));
        proc_close($proc);
    }
    @unlink($go);
    T::same(6, $total, 'every order approved once across all runs');
    T::same(6, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE to_status = 'MANUAL_APPROVED'"));
});

// ═════════════════════════════ refunds ═════════════════════════════

T::test('refund: partial then full; revenue and status follow; over-refund and repeat are refused', function () use ($ADMIN) {
    $gid = pf_setup();
    $o = ff_paid($gid, 'ig-followers-100k', '@big');          // ₹99
    $r = Fulfilment::refund((int) $o['id'], 4000, 'UPI-REF-A', $ADMIN, ff_req());
    T::same(false, $r['full']);
    $o2 = pf_order((int) $o['id']);
    T::same(4000, (int) $o2['refunded_paise']);
    T::same('MANUAL_PENDING', $o2['status'], 'a partial refund leaves the order in its state');
    T::same('partially_refunded', $o2['payment_status']);
    T::throws(HttpException::class, fn() => Fulfilment::refund((int) $o['id'], 6000, 'X', $ADMIN, ff_req()), 422);   // only 5900 left
    $r = Fulfilment::refund((int) $o['id'], 5900, 'UPI-REF-B', $ADMIN, ff_req());
    T::same(true, $r['full']);
    $o3 = pf_order((int) $o['id']);
    T::same('REFUNDED', $o3['status']);
    T::same(9900, (int) $o3['refunded_paise']);
    T::same('rejected', Db::val('SELECT state FROM manual_orders WHERE order_id = ?', [$o['id']]));
    T::throws(HttpException::class, fn() => Fulfilment::refund((int) $o['id'], 100, 'X', $ADMIN, ff_req()), 409);
    T::throws(HttpException::class, fn() => Fulfilment::refund((int) $o['id'], 0, 'X', $ADMIN, ff_req()), 422);
    T::throws(HttpException::class, fn() => Fulfilment::refund((int) $o['id'], 100, '', $ADMIN, ff_req()), 422);
    $unpaid = pf_place('ig-likes-100', 'https://instagram.com/p/ABCDEFG/', ['visitor_id' => null, 'session_id' => null], ff_ip());
    T::throws(HttpException::class, fn() => Fulfilment::refund((int) $unpaid['order']['id'], 100, 'X', $ADMIN, ff_req()), 409);
});

T::test('state machine: illegal manual transitions are refused with 409', function () use ($ADMIN) {
    $gid = pf_setup();
    $o = ff_paid($gid, 'ig-likes-100', 'https://instagram.com/p/ABCDEFG/');
    Fulfilment::cancel((int) $o['id'], $ADMIN, ff_req(), '');
    T::throws(HttpException::class, fn() => Fulfilment::complete((int) $o['id'], $ADMIN, ff_req()), 409);     // CANCELLED → COMPLETED
    T::throws(HttpException::class, fn() => Fulfilment::approve((int) $o['id'], $ADMIN, ff_req()), 409);
    T::same(false, Orders::transition((int) $o['id'], 'CANCELLED'), 'same-status transition is a no-op');
});
