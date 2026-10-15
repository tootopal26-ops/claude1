<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Payments\Gateways;
use App\Services\Fulfilment;
use App\Services\Orders;
use App\Services\PaymentService;
use App\Services\Settings;
use App\Services\Settlement;
use App\Services\WebhookService;
use App\Support\Clock;

/** Fresh DB + mocks + active gateway. @return int gateway id */
function pf_setup(): int
{
    T::startMocks();
    T::freshDb();
    T::alpha('POST', '/__control', ['reset' => true, 'style' => 'bearer', 'apiKey' => 'test-api-key-123', 'apiSecret' => 'test-api-secret-789', 'webhookSecret' => 'test-webhook-secret-456', 'webhookUrl' => '',
        'mode' => ['offline' => false, 'failCreate' => false, 'quota' => false, 'slowMs' => 0, 'noAmount' => false, 'currency' => 'INR']]);
    T::smm('POST', '/__reset');
    T::smm('POST', '/__control', ['offline' => false, 'slowMs' => 0, 'garbled' => false, 'hang' => false, 'currency' => 'INR']);
    return T::configureGateway();
}

/** Create an order+payment through the real checkout code. @return array{ref:string,order:array,payment:array} */
function pf_place(string $code = 'ig-followers-50', string $target = 'https://www.instagram.com/john/', array $visitor = ['visitor_id' => null, 'session_id' => null], string $ip = '203.0.113.9'): array
{
    $svc = Db::one('SELECT id FROM services WHERE code = ?', [$code]);
    $res = Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], $ip), ['service_id' => (int) $svc['id'], 'target' => $target], $visitor);
    $payment = Orders::paymentByRef($res['ref']);
    return ['ref' => $res['ref'], 'payment' => $payment, 'order' => Orders::byId((int) $payment['order_id'])];
}

/** Deliver a webhook to the real handler. @return array{0:int,1:string} [http status, body] */
function pf_webhook(int $gatewayId, array $body, ?string $secret = 'test-webhook-secret-456', ?string $signature = null): array
{
    $raw = json_encode($body);
    $sig = $signature ?? hash_hmac('sha256', $raw, (string) $secret);
    $res = WebhookService::handle(Request::fake('POST', '/webhooks/payments/' . $gatewayId, ['X-Alpha-Signature' => $sig, 'Content-Type' => 'application/json'], $raw), $gatewayId);
    foreach ($res->after as $fn) {   // the Kernel runs these after flushing the response
        $fn();
    }
    return [$res->status, $res->body];
}

function pf_evt(array $payment, array $over = []): array
{
    return $over + ['event_id' => 'evt_' . bin2hex(random_bytes(6)), 'order_id' => $payment['gateway_order_id'], 'status' => 'SUCCESS',
        'amount' => number_format($payment['amount_paise'] / 100, 2, '.', ''), 'utr' => 'UTR' . strtoupper(bin2hex(random_bytes(4))), 'provider' => 'bharatpe'];
}

function pf_order(int $id): array { return Orders::byId($id); }
function pf_payment(int $id): array { return Db::one('SELECT * FROM payments WHERE id = ?', [$id]); }

// ═════════════════════════════ gateway configuration ═════════════════════════════

T::test('gateway: connection test — ok / wrong key / unreachable', function () {
    $gid = pf_setup();
    $admin = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test'];
    $ok = Gateways::test($gid, Request::fake('POST', '/x'), $admin);
    T::true($ok['ok'], 'test with right key: ' . $ok['message']);

    T::configureGateway('WRONG-KEY-123');
    $bad = Gateways::test($gid, Request::fake('POST', '/x'), $admin);
    T::false($bad['ok']);
    T::contains('rejected the API key', $bad['message']);

    T::configureGateway();
    T::alpha('POST', '/__control', ['mode' => ['offline' => true]]);
    $off = Gateways::test($gid, Request::fake('POST', '/x'), $admin);
    T::false($off['ok']);
    T::contains('Could not reach', $off['message']);
});

T::test('gateway: secrets are encrypted at rest and never exposed to the admin UI', function () {
    $gid = pf_setup();
    $row = Gateways::row($gid);
    T::false(str_contains((string) $row['secrets_enc'], 'test-api-key-123'));
    T::false(str_contains((string) $row['secrets_enc'], 'test-webhook-secret-456'));
    $view = json_encode(Gateways::adminView($row));
    T::false(str_contains($view, 'test-api-key-123'), 'api key must not be in the admin payload');
    T::false(str_contains($view, 'test-webhook-secret-456'));
    T::true(Gateways::adminView($row)['has_api_key']);
});

T::test('gateway: save keeps stored secrets when fields are blank, rejects private URLs in production mode', function () {
    $gid = pf_setup();
    $admin = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test'];
    Gateways::save($gid, ['provider_pref' => 'paytm', 'api_key' => '', 'webhook_secret' => ''], Request::fake('POST', '/x'), $admin);
    $secrets = App\Core\Crypto::decryptJson(Gateways::row($gid)['secrets_enc']);
    T::same('test-api-key-123', $secrets['api_key'], 'blank field keeps the existing key');

    App\Core\Env::set('ALLOW_PRIVATE_API_HOSTS', 'false');
    try {
        $e = T::throws(HttpException::class, fn() => Gateways::save($gid, ['base_url' => 'https://127.0.0.1'], Request::fake('POST', '/x'), $admin), 422);
        T::true(isset($e->extra['fields']['base_url']));
    } finally {
        App\Core\Env::set('ALLOW_PRIVATE_API_HOSTS', 'true');
    }
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'gateway.saved'"));
    T::false(str_contains((string) Db::val("SELECT meta_json FROM audit_logs WHERE action = 'gateway.saved'"), 'test-api-key'), 'audit never holds secret values');
});

// ═════════════════════════════ checkout ═════════════════════════════

T::test('checkout: creates a pending order + 5-minute payment with the gateway-hosted URL', function () {
    pf_setup();
    Clock::setFixed(1_800_000_000);
    $p = pf_place('ig-followers-50', 'https://www.instagram.com/john/?igsh=1');
    $o = $p['order'];
    T::same('PENDING_PAYMENT', $o['status']);
    T::same('pending', $o['payment_status']);
    T::same(500, (int) $o['amount_paise'], 'price comes from the DB');
    T::same(50, (int) $o['quantity']);
    T::same('MANUAL', $o['mode']);
    T::same('https://www.instagram.com/john/', $o['target_url'], 'normalised link');
    T::same('instagram', $o['platform_slug']);
    T::true(str_starts_with($o['public_id'], 'SMB-'));
    T::same(32, strlen($o['access_token']));
    T::same('pending', $p['payment']['status']);
    T::true(str_starts_with((string) $p['payment']['payment_url'], 'http://127.0.0.1:4010/pay/SMB'));
    T::same($p['payment']['payment_url'], $p['payment']['qr_payload'], 'QR encodes the gateway-provided URL');
    T::same(Clock::sql(1_800_000_300), $p['payment']['expires_at'], 'session lasts exactly 5:00');
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM order_status_history WHERE order_id = ?', [$o['id']]));
    // the gateway received the amount in rupees and OUR order id
    $remote = T::alpha('GET', '/__state')['orders'][$p['payment']['gateway_order_id']] ?? null;
    T::same('5.00', $remote['amount'] ?? null);
    T::same($o['public_id'], $remote['remark1'] ?? null);
});

T::test('checkout: price/quantity/mode can never come from the client', function () {
    pf_setup();
    $svc = Db::one("SELECT id FROM services WHERE code = 'ig-followers-100k'");
    $res = Orders::checkout(Request::fake('POST', '/api/checkout'), [
        'service_id' => (int) $svc['id'], 'target' => 'https://www.instagram.com/john/', 'amount' => 1, 'amount_paise' => 1, 'price' => '0.01', 'quantity' => 999999, 'mode' => 'AUTO', 'cost' => 0,
    ], ['visitor_id' => null, 'session_id' => null]);
    $o = Orders::byId((int) Orders::paymentByRef($res['ref'])['order_id']);
    T::same(9900, (int) $o['amount_paise']);
    T::same(100000, (int) $o['quantity']);
    T::same('MANUAL', $o['mode']);
});

T::test('checkout: rejects bad input, unknown/inactive/deleted/misconfigured services, maintenance', function () {
    pf_setup();
    $svc = (int) Db::val("SELECT id FROM services WHERE code = 'ig-likes-100'");
    $go = fn(array $in) => Orders::checkout(Request::fake('POST', '/api/checkout', [], '', [], '198.51.100.' . random_int(1, 250)), $in, ['visitor_id' => null, 'session_id' => null]);

    T::throws(HttpException::class, fn() => $go(['service_id' => $svc, 'target' => '@john']), 422);                 // likes need a post link
    T::throws(HttpException::class, fn() => $go(['service_id' => $svc, 'target' => 'https://evil.com/p/ABCDEFG']), 422);
    T::throws(HttpException::class, fn() => $go(['service_id' => 999999, 'target' => 'https://instagram.com/p/ABCDEFG/']), 422);
    T::throws(HttpException::class, fn() => $go(['service_id' => -5, 'target' => 'x']), 422);
    T::throws(HttpException::class, fn() => $go(['service_id' => '1 OR 1=1', 'target' => 'x']), 422);
    T::throws(HttpException::class, fn() => $go(['service_id' => $svc]), 422);
    T::throws(HttpException::class, fn() => $go(['service_id' => $svc, 'target' => ['a']]), 422);

    Db::exec("UPDATE services SET status = 'inactive' WHERE id = ?", [$svc]);
    T::throws(HttpException::class, fn() => $go(['service_id' => $svc, 'target' => 'https://instagram.com/p/ABCDEFG/']), 422);
    Db::exec("UPDATE services SET status = 'active', deleted_at = ? WHERE id = ?", [Clock::sql(), $svc]);
    T::throws(HttpException::class, fn() => $go(['service_id' => $svc, 'target' => 'https://instagram.com/p/ABCDEFG/']), 422);
    Db::exec('UPDATE services SET deleted_at = NULL, price_paise = 0 WHERE id = ?', [$svc]);
    T::throws(HttpException::class, fn() => $go(['service_id' => $svc, 'target' => 'https://instagram.com/p/ABCDEFG/']), 422, 'zero price is never sellable');
    Db::exec('UPDATE services SET price_paise = 500, quantity = 0 WHERE id = ?', [$svc]);
    T::throws(HttpException::class, fn() => $go(['service_id' => $svc, 'target' => 'https://instagram.com/p/ABCDEFG/']), 422, 'zero quantity is never sellable');
    Db::exec('UPDATE services SET quantity = 100 WHERE id = ?', [$svc]);

    Settings::set('site.maintenance', true);
    $e = T::throws(HttpException::class, fn() => $go(['service_id' => $svc, 'target' => 'https://instagram.com/p/ABCDEFG/']), 503);
    T::same('maintenance', $e->errorCode);
    Settings::set('site.maintenance', false);

    T::same(0, (int) Db::val('SELECT COUNT(*) FROM orders'), 'nothing was created by any rejected attempt');
});

T::test('checkout: repeated click on the same pending checkout returns the SAME payment (no duplicate orders)', function () {
    pf_setup();
    $v = Db::insert('visitors', ['anon_id' => bin2hex(random_bytes(16)), 'first_seen_at' => Clock::sql(), 'last_seen_at' => Clock::sql()]);
    $a = pf_place('ig-followers-50', 'https://www.instagram.com/john/', ['visitor_id' => $v, 'session_id' => null]);
    $b = pf_place('ig-followers-50', 'instagram.com/john/', ['visitor_id' => $v, 'session_id' => null]);
    T::same($a['ref'], $b['ref']);
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM orders'));
    $c = pf_place('ig-followers-50', 'https://www.instagram.com/someoneelse/', ['visitor_id' => $v, 'session_id' => null]);
    T::true($c['ref'] !== $a['ref'], 'a different target is a different order');
});

T::test('checkout: gateway failures give a safe error and leave a FAILED order (no payment shown)', function () {
    pf_setup();
    foreach ([['offline' => true], ['failCreate' => true], ['quota' => true]] as $i => $mode) {
        T::alpha('POST', '/__control', ['mode' => array_merge(['offline' => false, 'failCreate' => false, 'quota' => false], $mode)]);
        $e = T::throws(HttpException::class, fn() => pf_place('ig-followers-50', 'https://www.instagram.com/john/', ['visitor_id' => null, 'session_id' => null], '198.51.100.' . (10 + $i)), 503);
        T::same('payments_unavailable', $e->errorCode);
        T::false(str_contains($e->getMessage(), 'quota'), 'internal gateway detail is not shown to customers');
    }
    T::same(3, (int) Db::val("SELECT COUNT(*) FROM orders WHERE status = 'FAILED'"));
    T::same(3, (int) Db::val("SELECT COUNT(*) FROM payments WHERE status = 'failed'"));
    T::contains('quota', (string) Db::val("SELECT GROUP_CONCAT(failure_reason) FROM payments"), 'admin-side reason is recorded');
});

T::test('checkout: no active gateway → 503, and a gateway with a wrong API key → 503', function () {
    pf_setup();
    Db::exec('UPDATE payment_gateways SET is_active = 0');
    T::throws(HttpException::class, fn() => pf_place(), 503);
    T::configureGateway('WRONG-KEY');
    T::throws(HttpException::class, fn() => pf_place('ig-followers-50', 'https://www.instagram.com/john/', ['visitor_id' => null, 'session_id' => null], '198.51.100.77'), 503);
});

// ═════════════════════════════ webhook ═════════════════════════════

T::test('webhook: bad / missing signature is rejected and changes nothing', function () {
    $gid = pf_setup();
    $p = pf_place();
    [$status] = pf_webhook($gid, pf_evt($p['payment']), 'wrong-secret');
    T::same(401, $status);
    [$status] = pf_webhook($gid, pf_evt($p['payment']), null, '');
    T::same(401, $status);
    [$status] = pf_webhook($gid, pf_evt($p['payment']), null, str_repeat('a', 64));
    T::same(401, $status);
    T::same('PENDING_PAYMENT', pf_order((int) $p['order']['id'])['status']);
    T::same(3, (int) Db::val("SELECT COUNT(*) FROM webhook_events WHERE status = 'invalid' AND signature_valid = 0"));
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM webhook_events WHERE dedupe_key IS NOT NULL'));
});

T::test('webhook: valid success pays the order; MANUAL service enters the manual queue', function () {
    $gid = pf_setup();
    $p = pf_place();
    [$status, $body] = pf_webhook($gid, pf_evt($p['payment'], ['utr' => 'UTR123456']));
    T::same(200, $status);
    T::same('ok', $body);
    $o = pf_order((int) $p['order']['id']);
    T::same('MANUAL_PENDING', $o['status']);
    T::same('success', $o['payment_status']);
    T::true($o['paid_at'] !== null && $o['paid_day'] !== null);
    $pay = pf_payment((int) $p['payment']['id']);
    T::same('success', $pay['status']);
    T::same('UTR123456', $pay['provider_txn_id']);
    T::same('webhook', $pay['verified_via']);
    T::same(500, (int) $pay['paid_amount_paise']);
    T::same(0, (int) $pay['late']);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM manual_orders WHERE order_id = ? AND state = 'pending'", [$o['id']]));
    $hist = Db::col('SELECT to_status FROM order_status_history WHERE order_id = ? ORDER BY id', [$o['id']]);
    T::same(['PENDING_PAYMENT', 'PAID', 'MANUAL_PENDING'], $hist);
    T::same('processed', Db::val('SELECT status FROM webhook_events ORDER BY id DESC LIMIT 1'));
});

T::test('webhook: duplicate / replayed delivery is acknowledged but processed ONCE', function () {
    $gid = pf_setup();
    $p = pf_place();
    $evt = pf_evt($p['payment']);
    T::same([200, 'ok'], pf_webhook($gid, $evt));
    for ($i = 0; $i < 5; $i++) {
        T::same([200, 'duplicate'], pf_webhook($gid, $evt));
    }
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND to_status = 'PAID'", [$p['order']['id']]));
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM manual_orders'));
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM webhook_events'));
    T::same(5, (int) Db::val('SELECT attempts - 1 FROM webhook_events'));
    T::same(500, (int) Db::val('SELECT paid_amount_paise FROM payments'));
    T::same(1, (int) Db::val("SELECT paid_orders_count FROM users") ?: 1);
});

T::test('webhook: a NEW event id for an already-paid payment never double-counts', function () {
    $gid = pf_setup();
    $p = pf_place();
    pf_webhook($gid, pf_evt($p['payment']));
    [$status] = pf_webhook($gid, pf_evt($p['payment']));      // different event_id and utr, same payment
    T::same(200, $status);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND to_status = 'PAID'", [$p['order']['id']]));
    T::same('processed', Db::val('SELECT status FROM webhook_events ORDER BY id DESC LIMIT 1'));
});

T::test('webhook: wrong amount → flagged for review, order stays unpaid, nothing fulfilled', function () {
    $gid = pf_setup();
    $p = pf_place('ig-followers-100k', 'https://www.instagram.com/john/');
    [$status] = pf_webhook($gid, pf_evt($p['payment'], ['amount' => '1.00']));      // paid ₹1 for a ₹99 order
    T::same(200, $status);
    $pay = pf_payment((int) $p['payment']['id']);
    T::same('mismatch', $pay['status']);
    T::contains('Amount mismatch', (string) $pay['failure_reason']);
    $o = pf_order((int) $p['order']['id']);
    T::same('PENDING_PAYMENT', $o['status']);
    T::true($o['paid_at'] === null);
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM manual_orders'));
    T::same('mismatch', Db::val('SELECT status FROM webhook_events ORDER BY id DESC LIMIT 1'));
    // a later "correct" automatic event cannot silently override a flagged payment
    pf_webhook($gid, pf_evt($p['payment']));
    T::same('mismatch', pf_payment((int) $p['payment']['id'])['status']);
    // missing amount is also unverifiable
    $q = pf_place('ig-likes-100', 'https://instagram.com/p/ABCDEFG/', ['visitor_id' => null, 'session_id' => null], '198.51.100.5');
    pf_webhook($gid, pf_evt($q['payment'], ['amount' => '']));
    T::same('mismatch', pf_payment((int) $q['payment']['id'])['status']);
});

T::test('webhook: unknown order id is acknowledged and ignored; malformed JSON is rejected', function () {
    $gid = pf_setup();
    [$s, $b] = pf_webhook($gid, ['event_id' => 'e1', 'order_id' => 'NOPE', 'status' => 'SUCCESS', 'amount' => '5.00', 'utr' => 'U1']);
    T::same([200, 'ok'], [$s, $b]);
    T::same('ignored', Db::val('SELECT status FROM webhook_events ORDER BY id DESC LIMIT 1'));
    $raw = 'not json';
    $res = WebhookService::handle(Request::fake('POST', '/w', ['X-Alpha-Signature' => hash_hmac('sha256', $raw, 'test-webhook-secret-456')], $raw), $gid);
    T::same(400, $res->status);
    T::same(404, WebhookService::handle(Request::fake('POST', '/w', [], '{}'), 999)->status);
});

T::test('webhook: a forged event cannot poison the dedupe table', function () {
    $gid = pf_setup();
    $p = pf_place();
    $evt = pf_evt($p['payment'], ['event_id' => 'evt_victim']);
    T::same(401, pf_webhook($gid, $evt, 'attacker-secret')[0]);      // attacker pre-registers the victim's event id
    T::same([200, 'ok'], pf_webhook($gid, $evt));                    // the real one still goes through
    T::same('MANUAL_PENDING', pf_order((int) $p['order']['id'])['status']);
});

T::test('webhook: FAILED and EXPIRED events close the session; they cannot undo a paid one', function () {
    $gid = pf_setup();
    $a = pf_place('ig-followers-50', 'https://www.instagram.com/a/', ['visitor_id' => null, 'session_id' => null], '198.51.100.21');
    pf_webhook($gid, pf_evt($a['payment'], ['status' => 'FAILED', 'utr' => '']));
    T::same('failed', pf_payment((int) $a['payment']['id'])['status']);
    T::same('FAILED', pf_order((int) $a['order']['id'])['status']);

    $b = pf_place('ig-followers-50', 'https://www.instagram.com/b/', ['visitor_id' => null, 'session_id' => null], '198.51.100.22');
    pf_webhook($gid, pf_evt($b['payment'], ['status' => 'EXPIRED', 'utr' => '']));
    T::same('expired', pf_payment((int) $b['payment']['id'])['status']);
    T::same('EXPIRED', pf_order((int) $b['order']['id'])['status']);

    $c = pf_place('ig-followers-50', 'https://www.instagram.com/c/', ['visitor_id' => null, 'session_id' => null], '198.51.100.23');
    pf_webhook($gid, pf_evt($c['payment']));
    pf_webhook($gid, pf_evt($c['payment'], ['status' => 'FAILED', 'utr' => '']));
    T::same('success', pf_payment((int) $c['payment']['id'])['status'], 'a paid payment stays paid');
    T::same('MANUAL_PENDING', pf_order((int) $c['order']['id'])['status']);
});

T::test('webhook: the same bank reference (UTR) cannot pay two different orders', function () {
    $gid = pf_setup();
    $a = pf_place('ig-followers-50', 'https://www.instagram.com/a/', ['visitor_id' => null, 'session_id' => null], '198.51.100.31');
    $b = pf_place('ig-followers-50', 'https://www.instagram.com/b/', ['visitor_id' => null, 'session_id' => null], '198.51.100.32');
    pf_webhook($gid, pf_evt($a['payment'], ['utr' => 'SAMEUTR1']));
    pf_webhook($gid, pf_evt($b['payment'], ['utr' => 'SAMEUTR1']));
    T::same('success', pf_payment((int) $a['payment']['id'])['status']);
    T::same('mismatch', pf_payment((int) $b['payment']['id'])['status']);
    T::same('PENDING_PAYMENT', pf_order((int) $b['order']['id'])['status']);
});

// ═════════════════════════════ concurrency ═════════════════════════════

T::test('settle: 8 parallel processes race on ONE payment — exactly one wins', function () {
    $gid = pf_setup();
    $p = pf_place();
    $go = sys_get_temp_dir() . '/smb-go-' . getmypid();
    @unlink($go);
    $procs = [];
    for ($i = 0; $i < 8; $i++) {
        $procs[] = proc_open(['php', SMB_ROOT . '/tests/fixtures/settle_worker.php', (string) $p['payment']['id'], $go, '500', 'UTRRACE1'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    usleep(700_000);          // let every worker boot and reach the barrier
    touch($go);
    $results = [];
    foreach ($procs as $i => $proc) {
        $results[] = trim((string) stream_get_contents($pipes[$i][1]));
        proc_close($proc);
    }
    @unlink($go);
    $counts = array_count_values($results);
    T::same(1, $counts['"settled"'] ?? 0, 'exactly one worker settled: ' . json_encode($counts));
    T::same(7, $counts['"already_settled"'] ?? 0);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND to_status = 'PAID'", [$p['order']['id']]));
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM manual_orders'));
    T::same('MANUAL_PENDING', pf_order((int) $p['order']['id'])['status']);
});

// ═════════════════════════════ polling / expiry / late payments ═════════════════════════════

T::test('poll: a lost webhook is recovered by server-side verification with the gateway', function () {
    pf_setup();
    $p = pf_place();
    T::alpha('POST', '/__pay/' . $p['payment']['gateway_order_id'] . '/success', ['noWebhook' => true, 'utr' => 'UTRPOLL1']);
    $r = PaymentService::refresh($p['payment']);
    T::same('success', $r['payment']['status']);
    T::same('poll', $r['payment']['verified_via']);
    T::same('MANUAL_PENDING', pf_order((int) $p['order']['id'])['status']);
    // polling again changes nothing
    PaymentService::refresh(pf_payment((int) $p['payment']['id']));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND to_status = 'PAID'", [$p['order']['id']]));
});

T::test('poll: wrong amount reported by the gateway is not trusted', function () {
    pf_setup();
    $p = pf_place();
    T::alpha('POST', '/__pay/' . $p['payment']['gateway_order_id'] . '/success', ['noWebhook' => true]);
    T::alpha('POST', '/__control', ['mode' => ['noAmount' => true]]);
    $r = PaymentService::refresh($p['payment']);
    T::same('mismatch', $r['payment']['status'], 'success without a verifiable amount is held for review');
});

T::test('poll: gateway lookups are rate-limited per payment (no hammering the gateway)', function () {
    pf_setup();
    Clock::setFixed(1_800_000_000);
    $p = pf_place();
    PaymentService::refresh($p['payment']);
    PaymentService::refresh(pf_payment((int) $p['payment']['id']));
    PaymentService::refresh(pf_payment((int) $p['payment']['id']));
    T::same(1, (int) pf_payment((int) $p['payment']['id'])['poll_count'], 'three polls within 4 s hit the gateway once');
    Clock::setFixed(1_800_000_005);
    PaymentService::refresh(pf_payment((int) $p['payment']['id']));
    T::same(2, (int) pf_payment((int) $p['payment']['id'])['poll_count']);
});

T::test('expiry: after 5:00 the session expires — unpaid order EXPIRED, never shown as success', function () {
    pf_setup();
    Clock::setFixed(1_800_000_000);
    $p = pf_place();
    Clock::setFixed(1_800_000_299);
    T::same('pending', PaymentService::refresh($p['payment'])['payment']['status'], 'still valid at 4:59');
    Clock::setFixed(1_800_000_301);
    $r = PaymentService::refresh(pf_payment((int) $p['payment']['id']));
    T::same('expired', $r['payment']['status']);
    T::same('EXPIRED', pf_order((int) $p['order']['id'])['status']);
    T::same('expired', pf_order((int) $p['order']['id'])['payment_status']);
});

T::test('expiry: a payment confirmed AFTER the session ended is still credited, flagged as late', function () {
    $gid = pf_setup();
    Clock::setFixed(1_800_000_000);
    $p = pf_place();
    Clock::setFixed(1_800_000_400);
    PaymentService::refresh($p['payment']);                       // → expired
    T::same('EXPIRED', pf_order((int) $p['order']['id'])['status']);
    Clock::setFixed(1_800_000_700);                              // customer paid anyway; webhook arrives 11 minutes in
    pf_webhook($gid, pf_evt(pf_payment((int) $p['payment']['id'])));
    $o = pf_order((int) $p['order']['id']);
    T::same('MANUAL_PENDING', $o['status'], 'money was received, so the order is honoured');
    T::same(1, (int) $o['late_payment']);
    T::same(1, (int) pf_payment((int) $p['payment']['id'])['late']);
});

T::test('expiry: cron sweep — double-checks with the gateway before expiring, then expires the rest', function () {
    pf_setup();
    Clock::setFixed(1_800_000_000);
    $paid = pf_place('ig-followers-50', 'https://www.instagram.com/paid/', ['visitor_id' => null, 'session_id' => null], '198.51.100.41');
    $idle = pf_place('ig-followers-50', 'https://www.instagram.com/idle/', ['visitor_id' => null, 'session_id' => null], '198.51.100.42');
    T::alpha('POST', '/__pay/' . $paid['payment']['gateway_order_id'] . '/success', ['noWebhook' => true]);   // paid, webhook lost
    Clock::setFixed(1_800_000_400);
    $stats = PaymentService::sweep();
    T::same(1, $stats['settled'], json_encode($stats));
    T::same(1, $stats['expired']);
    T::same('MANUAL_PENDING', pf_order((int) $paid['order']['id'])['status']);
    T::same('EXPIRED', pf_order((int) $idle['order']['id'])['status']);
});

T::test('payment session: a payment stuck in "creating" is failed by the sweep', function () {
    pf_setup();
    Clock::setFixed(1_800_000_000);
    $p = pf_place();
    Db::exec("UPDATE payments SET status = 'creating' WHERE id = ?", [$p['payment']['id']]);
    Clock::setFixed(1_800_000_200);
    T::same(1, PaymentService::sweep()['failed']);
    T::same('failed', pf_payment((int) $p['payment']['id'])['status']);
});

T::test('admin: manual payment confirmation settles once and is recorded', function () {
    pf_setup();
    $p = pf_place();
    $admin = ['id' => 1, 'username' => 'root', 'email' => 'r@x.test'];
    $r = Settlement::settle((int) $p['payment']['id'], ['source' => 'admin', 'amount_paise' => 500, 'utr' => 'BANK-REF-9', 'admin' => $admin, 'note' => 'seen in bank statement']);
    T::same('settled', $r['result']);
    T::same('MANUAL_PENDING', pf_order((int) $p['order']['id'])['status']);
    T::same('admin', pf_payment((int) $p['payment']['id'])['verified_via']);
    T::same('already_settled', Settlement::settle((int) $p['payment']['id'], ['source' => 'admin', 'amount_paise' => 500, 'admin' => $admin])['result']);
});
