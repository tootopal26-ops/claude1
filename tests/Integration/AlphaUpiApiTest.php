<?php
declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Payments\AlphaUpiGateway;
use App\Payments\Gateways;
use App\Payments\PaymentStatusResult;
use App\Services\PaymentService;
use App\Services\WebhookService;

/**
 * The current Alpha UPI API (alphaupi.site): X-API-Key + X-API-Secret request headers, POST /api/create-order → 201 {status:"success",
 * data:{payment_url,…}}, POST /api/check-status → {status:"success", data:{payment_status,…}}, and a webhook that has no event_id
 * ({event:"payment.success", status:"success", order_id, amount, utr}) signed with HMAC-SHA256 in a *-Signature header.
 * The mock gateway (tools/mock-alpha-upi) speaks it when its style is "headers"; the older Bearer API is covered by PaymentFlowTest.
 */

const AA_KEY = 'pi_live_testkey_1234567890';
const AA_SECRET = 'sk_live_testsecret_0987654321';
const AA_WEBHOOK = 'whsec_test_abcdef123456';
const AA_ADMIN = ['id' => 1, 'username' => 'tester', 'email' => 't@example.test'];

/** Fresh DB + mocks, the mock gateway switched to the current API, the gateway row configured for it. @return int gateway id */
function aa_setup(): int
{
    T::startMocks();
    T::freshDb();
    T::alpha('POST', '/__control', ['reset' => true, 'style' => 'headers', 'apiKey' => AA_KEY, 'apiSecret' => AA_SECRET, 'webhookSecret' => AA_WEBHOOK, 'webhookUrl' => '',
        'mode' => ['offline' => false, 'failCreate' => false, 'quota' => false, 'slowMs' => 0, 'noAmount' => false]]);
    T::smm('POST', '/__reset');
    T::smm('POST', '/__control', ['offline' => false, 'slowMs' => 0, 'garbled' => false, 'hang' => false]);
    return T::configureGateway(AA_KEY, AA_WEBHOOK, 4010, 'headers', AA_SECRET);
}

/** Leave the shared mock the way the other suites expect it. */
function aa_done(): void
{
    T::alpha('POST', '/__control', ['reset' => true, 'style' => 'bearer', 'apiKey' => 'test-api-key-123', 'apiSecret' => 'test-api-secret-789', 'webhookSecret' => 'test-webhook-secret-456',
        'webhookUrl' => '', 'mode' => ['offline' => false, 'failCreate' => false, 'quota' => false, 'slowMs' => 0, 'noAmount' => false]]);
    T::configureGateway();
}

/** The webhook the gateway documents. */
function aa_evt(array $payment, array $over = []): array
{
    return $over + ['event' => 'payment.success', 'status' => 'success', 'order_id' => $payment['gateway_order_id'],
        'amount' => (float) number_format($payment['amount_paise'] / 100, 2, '.', ''), 'utr' => 'UTR' . strtoupper(bin2hex(random_bytes(4)))];
}

/** Deliver a webhook to the real handler. $headers: name => value, or null for the documented X-PayIndia-Signature. @return array{0:int,1:string} */
function aa_webhook(int $gid, array $body, ?array $headers = null, string $secret = AA_WEBHOOK): array
{
    $raw = json_encode($body);
    $headers ??= ['X-PayIndia-Signature' => hash_hmac('sha256', $raw, $secret)];
    $res = WebhookService::handle(Request::fake('POST', '/webhooks/payments/' . $gid, $headers + ['Content-Type' => 'application/json'], $raw), $gid);
    foreach ($res->after as $fn) {
        $fn();
    }
    return [$res->status, $res->body];
}

/** The calls the mock gateway has received (bodies only — never the credentials). */
function aa_requests(): array { return T::alpha('GET', '/__state')['requests'] ?? []; }

// ═════════════════════════════ connection test ═════════════════════════════

T::test('alpha-api: connection test — reachable / wrong key / wrong secret / web page instead of the API / unreachable', function () {
    $gid = aa_setup();
    $probe = fn() => Gateways::adapter(Gateways::row($gid))->testConnection();

    $r = $probe();
    T::true($r->ok, $r->message);
    T::same('warning', $r->level, 'an unknown test order is the expected answer; only a real order proves the credentials');
    T::contains('₹5 test order', $r->message);
    $sent = aa_requests();
    T::same(['/api/check-status'], array_column($sent, 'path'), 'the documented status endpoint is used (not /api/check-order-status)');
    T::same('headers', $sent[0]['auth']);
    T::true(str_starts_with((string) $sent[0]['body']['order_id'], 'SMB-CONNECTION-TEST-'));

    T::alpha('POST', '/__control', ['apiKey' => 'pi_live_somebody_else']);
    $r = $probe();
    T::false($r->ok);
    T::contains('rejected the API key or API secret', $r->message);

    T::alpha('POST', '/__control', ['apiKey' => AA_KEY, 'apiSecret' => 'sk_live_somebody_else']);
    T::false($probe()->ok, 'a wrong secret is refused too');

    T::alpha('POST', '/__control', ['apiSecret' => AA_SECRET]);
    Db::exec("UPDATE payment_gateways SET config_json = ? WHERE id = ?", [json_encode(['base_url' => 'http://127.0.0.1:4010/html404', 'api_style' => 'headers']), $gid]);
    $r = $probe();
    T::false($r->ok);
    T::contains('web page (HTTP 404)', $r->message);
    T::contains('https://alphaupi.site', $r->message, 'the message says what the base URL should look like');

    Db::exec("UPDATE payment_gateways SET config_json = ? WHERE id = ?", [json_encode(['base_url' => 'http://127.0.0.1:4999', 'api_style' => 'headers']), $gid]);
    $r = $probe();
    T::false($r->ok);
    T::contains('Could not reach', $r->message);

    aa_done();
});

// ═════════════════════════════ create order ═════════════════════════════

T::test('alpha-api: checkout sends the documented request and shows the hosted payment URL as the QR', function () {
    aa_setup();
    $p = pf_place('ig-followers-50');            // ₹5
    $calls = array_values(array_filter(aa_requests(), fn($c) => $c['path'] === '/api/create-order'));
    T::same(1, count($calls));
    $b = $calls[0]['body'];
    T::same('headers', $calls[0]['auth']);
    T::same('5.00', $b['amount'], 'rupees with two decimals, as a string — never a float');
    T::same($p['payment']['gateway_order_id'], $b['order_id']);
    T::same('Customer', $b['customer_name'], 'customer_name is required by the API');
    T::contains('/pay/' . $p['ref'], $b['callback_url']);
    T::contains($p['order']['public_id'], $b['description']);
    T::true(!isset($b['redirect_url'], $b['remark1']), 'none of the older API\'s fields');
    T::same('PENDING', T::alpha('GET', '/__state')['orders'][$p['payment']['gateway_order_id']]['status']);

    T::same('pending', $p['payment']['status']);
    T::contains('/pay/' . $p['payment']['gateway_order_id'], (string) $p['payment']['payment_url']);
    T::same($p['payment']['payment_url'], $p['payment']['qr_payload'], 'the QR encodes the gateway-provided URL');
    $stored = (string) $p['payment']['create_response'];
    T::contains('payment_url', $stored);
    foreach ([AA_KEY, AA_SECRET, AA_WEBHOOK] as $secret) {
        T::false(str_contains($stored, $secret), 'no credential is ever stored with the order');
    }
    aa_done();
});

T::test('alpha-api: refusals are safe for customers and readable for the admin (quota, no provider, wrong credentials, offline)', function () {
    aa_setup();
    $cases = [
        ['mode' => ['quota' => true], 'reason' => 'Plan quota exhausted'],
        ['mode' => ['failCreate' => true], 'reason' => 'No connected provider.'],
        ['mode' => ['offline' => true], 'reason' => 'Network error'],
        ['secret' => 'sk_live_somebody_else', 'reason' => 'Invalid API credentials'],
    ];
    foreach ($cases as $i => $c) {
        T::alpha('POST', '/__control', ['apiSecret' => $c['secret'] ?? AA_SECRET, 'mode' => array_merge(['offline' => false, 'failCreate' => false, 'quota' => false], $c['mode'] ?? [])]);
        $e = T::throws(HttpException::class, fn() => pf_place('ig-followers-50', '@john', ['visitor_id' => null, 'session_id' => null], '198.51.100.' . (20 + $i)), 503);
        T::same('payments_unavailable', $e->errorCode);
        T::false(str_contains($e->getMessage(), $c['reason']), 'the customer never sees the gateway\'s wording');
    }
    T::same(4, (int) Db::val("SELECT COUNT(*) FROM orders WHERE status = 'FAILED'"));
    $reasons = (string) Db::val('SELECT GROUP_CONCAT(failure_reason SEPARATOR " | ") FROM payments');
    foreach ($cases as $c) {
        T::contains($c['reason'], $reasons, 'the admin sees why: ' . $c['reason']);
    }
    aa_done();
});

// ═════════════════════════════ webhook ═════════════════════════════

T::test('alpha-api: the documented webhook (no event_id, X-PayIndia-Signature) settles the order once; a redelivery is a duplicate', function () {
    $gid = aa_setup();
    $p = pf_place();
    $evt = aa_evt($p['payment'], ['utr' => 'UTR615372849102']);
    [$status, $body] = aa_webhook($gid, $evt);
    T::same([200, 'ok'], [$status, $body]);
    $pay = pf_payment((int) $p['payment']['id']);
    T::same('success', $pay['status']);
    T::same('UTR615372849102', $pay['provider_txn_id']);
    T::same('webhook', $pay['verified_via']);
    T::same('MANUAL_PENDING', pf_order((int) $p['order']['id'])['status']);

    T::same([200, 'duplicate'], aa_webhook($gid, $evt), 'the same delivery again is acknowledged and ignored');
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND to_status = 'PAID'", [$p['order']['id']]));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM webhook_events WHERE status = 'processed'"));
    // a retry whose body differs (extra field) is a new event for the dedupe table, but settlement is idempotent
    [$s2] = aa_webhook($gid, $evt + ['paid_at' => '2026-10-15 12:00:00']);
    T::same(200, $s2);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND to_status = 'PAID'", [$p['order']['id']]), 'never paid twice');
    aa_done();
});

T::test('alpha-api: the signature is accepted in any header that mentions "signature", with or without sha256=; anything else is refused', function () {
    $gid = aa_setup();
    $sig = fn(string $raw, string $secret = AA_WEBHOOK) => hash_hmac('sha256', $raw, $secret);
    $variants = [
        'X-Alpha-Signature' => fn($raw) => $sig($raw),
        'X-Lola-Pay-Signature' => fn($raw) => $sig($raw),
        'X-Hub-Signature-256' => fn($raw) => 'sha256=' . $sig($raw),
        'X-Something-New-Signature' => fn($raw) => strtoupper($sig($raw)),
    ];
    $n = 100;
    foreach ($variants as $header => $make) {
        $p = pf_place('ig-followers-50', '@john', ['visitor_id' => null, 'session_id' => null], '198.51.100.' . $n++);
        $raw = json_encode(aa_evt($p['payment']));
        [$status] = aa_webhook($gid, json_decode($raw, true), [$header => $make($raw)]);
        T::same(200, $status, $header);
        T::same('success', pf_payment((int) $p['payment']['id'])['status'], $header . ' settled the payment');
    }
    $p = pf_place('ig-followers-50', '@john', ['visitor_id' => null, 'session_id' => null], '198.51.100.250');
    $raw = json_encode(aa_evt($p['payment']));
    foreach ([
        'wrong secret' => ['X-PayIndia-Signature' => $sig($raw, 'not-the-secret')],
        'no signature header' => [],
        'digest in an unrelated header' => ['X-Request-Id' => $sig($raw)],
        'empty signature' => ['X-PayIndia-Signature' => ''],
        'digest of another body' => ['X-PayIndia-Signature' => $sig($raw . ' ')],
    ] as $name => $headers) {
        [$status] = aa_webhook($gid, json_decode($raw, true), $headers);
        T::same(401, $status, $name);
    }
    T::same('pending', pf_payment((int) $p['payment']['id'])['status'], 'forged deliveries changed nothing');
    aa_done();
});

T::test('alpha-api: the unified parser also takes the older payload (event_id, upper-case status) and a payload nested under "data"', function () {
    $gid = aa_setup();
    $p = pf_place();
    $older = pf_evt($p['payment']);                      // {event_id, status:"SUCCESS", …}
    [$status] = aa_webhook($gid, $older, ['X-Alpha-Signature' => hash_hmac('sha256', json_encode($older), AA_WEBHOOK)]);
    T::same(200, $status);
    T::same('success', pf_payment((int) $p['payment']['id'])['status']);

    $p2 = pf_place('ig-followers-50', '@john', ['visitor_id' => null, 'session_id' => null], '198.51.100.201');
    [$status] = aa_webhook($gid, ['event' => 'payment.success', 'data' => ['order_id' => $p2['payment']['gateway_order_id'], 'status' => 'success', 'amount' => '5.00', 'utr' => 'UTRNESTED1']]);
    T::same(200, $status);
    T::same('UTRNESTED1', pf_payment((int) $p2['payment']['id'])['provider_txn_id']);
    aa_done();
});

T::test('alpha-api: a delivery with the wrong amount is flagged for review, never fulfilled', function () {
    $gid = aa_setup();
    $p = pf_place();
    aa_webhook($gid, aa_evt($p['payment'], ['amount' => 1.0]));
    $pay = pf_payment((int) $p['payment']['id']);
    T::same('mismatch', $pay['status']);
    T::same('PENDING_PAYMENT', pf_order((int) $p['order']['id'])['status']);
    aa_done();
});

// ═════════════════════════════ status lookups ═════════════════════════════

T::test('alpha-api: a lost webhook is recovered through /api/check-status (and an unknown order or bad credentials change nothing)', function () {
    aa_setup();
    $p = pf_place();
    $id = $p['payment']['gateway_order_id'];
    $adapter = fn() => Gateways::adapter(Gateways::row((int) $p['payment']['gateway_id']));

    $r = $adapter()->getPaymentStatus($id);
    T::same([true, PaymentStatusResult::PENDING, 500], [$r->reachable, $r->status, $r->amountPaise]);
    T::same(PaymentStatusResult::UNKNOWN, $adapter()->getPaymentStatus('NO-SUCH-ORDER')->status);
    T::true($adapter()->getPaymentStatus('NO-SUCH-ORDER')->reachable, 'an unknown order is an answer, not an outage');

    T::alpha('POST', '/__pay/' . $id . '/success', ['noWebhook' => true, 'utr' => 'UTRPOLL2']);
    T::alpha('POST', '/__control', ['apiSecret' => 'sk_live_somebody_else']);
    T::false($adapter()->getPaymentStatus($id)->reachable, 'refused credentials: we cannot tell, try again later');
    T::alpha('POST', '/__control', ['apiSecret' => AA_SECRET]);

    $r = PaymentService::refresh($p['payment']);
    T::same('success', $r['payment']['status']);
    T::same('poll', $r['payment']['verified_via']);
    T::same('UTRPOLL2', pf_payment((int) $p['payment']['id'])['provider_txn_id']);
    T::same('paytm', pf_payment((int) $p['payment']['id'])['gateway_method'], 'the status answer names the UPI provider');
    T::same('MANUAL_PENDING', pf_order((int) $p['order']['id'])['status']);
    T::true(count(array_filter(aa_requests(), fn($c) => $c['path'] === '/api/check-status' && ($c['body']['order_id'] ?? '') === $id)) >= 1, 'the documented endpoint was asked');
    aa_done();
});

T::test('alpha-api: failed and expired payments are recognised; a success without a verifiable amount is held for review', function () {
    aa_setup();
    $a = pf_place();
    $b = pf_place('ig-followers-50', '@john', ['visitor_id' => null, 'session_id' => null], '198.51.100.202');
    $c = pf_place('ig-followers-50', '@john', ['visitor_id' => null, 'session_id' => null], '198.51.100.203');
    T::alpha('POST', '/__pay/' . $a['payment']['gateway_order_id'] . '/fail', ['noWebhook' => true]);
    T::alpha('POST', '/__pay/' . $b['payment']['gateway_order_id'] . '/expire', ['noWebhook' => true]);
    T::same('failed', PaymentService::refresh($a['payment'])['payment']['status']);
    T::same('expired', PaymentService::refresh($b['payment'])['payment']['status']);

    T::alpha('POST', '/__pay/' . $c['payment']['gateway_order_id'] . '/success', ['noWebhook' => true]);
    T::alpha('POST', '/__control', ['mode' => ['noAmount' => true]]);
    T::same('mismatch', PaymentService::refresh($c['payment'])['payment']['status']);
    aa_done();
});

T::test('alpha-api: gateway wording is mapped conservatively — only unmistakable words mean paid', function () {
    foreach (['success', 'SUCCESS', ' Success ', 'successful', 'paid', 'Completed'] as $w) {
        T::same(PaymentStatusResult::SUCCESS, AlphaUpiGateway::mapStatus($w), $w);
    }
    foreach (['pending', 'PENDING', 'created', 'initiated', 'processing'] as $w) {
        T::same(PaymentStatusResult::PENDING, AlphaUpiGateway::mapStatus($w), $w);
    }
    foreach (['failed', 'FAILED', 'cancelled', 'canceled', 'rejected'] as $w) {
        T::same(PaymentStatusResult::FAILED, AlphaUpiGateway::mapStatus($w), $w);
    }
    foreach (['expired', 'EXPIRED', 'timeout'] as $w) {
        T::same(PaymentStatusResult::EXPIRED, AlphaUpiGateway::mapStatus($w), $w);
    }
    foreach (['', null, 'ok', 'true', '1', 'processed', 'captured', 'complete', 'refunded', 'successfully-not', [], 1, true] as $w) {
        T::same(PaymentStatusResult::UNKNOWN, AlphaUpiGateway::mapStatus($w), 'unfamiliar wording must not count: ' . json_encode($w));
    }
    T::same(PaymentStatusResult::SUCCESS, AlphaUpiGateway::mapStatus(null, 'payment.success'), 'the event name is only a fallback');
    T::same(PaymentStatusResult::FAILED, AlphaUpiGateway::mapStatus('', 'payment.failed'));
    T::same(PaymentStatusResult::FAILED, AlphaUpiGateway::mapStatus('failed', 'payment.success'), 'a status that is present wins over the event name');
});

// ═════════════════════════════ admin settings ═════════════════════════════

T::test('alpha-api: the API secret is write-only, encrypted and needed to activate; the API type can be switched without losing secrets', function () {
    T::startMocks();
    T::freshDb();
    $gid = (int) Db::val('SELECT id FROM payment_gateways ORDER BY id LIMIT 1');
    $req = Request::fake('PUT', '/admin/api/gateway/' . $gid);
    $fields = function (array $in) use ($gid, $req): array {
        try {
            Gateways::save($gid, $in, $req, AA_ADMIN);
        } catch (HttpException $e) {
            T::same(422, $e->status);
            return $e->extra['fields'] ?? [];
        }
        return [];
    };

    $view = Gateways::adminView(Gateways::row($gid));
    T::same('headers', $view['api_style'], 'a new gateway uses the current API');
    T::same(false, $view['has_api_secret']);

    Gateways::save($gid, ['base_url' => 'https://alphaupi.site/api/'], $req, AA_ADMIN);
    T::same('https://alphaupi.site', Gateways::adminView(Gateways::row($gid))['base_url'], 'a pasted ".../api" is trimmed: the client adds /api/… itself');
    Gateways::save($gid, ['base_url' => 'http://127.0.0.1:4010/api'], $req, AA_ADMIN);

    $f = $fields(['is_active' => true, 'api_key' => AA_KEY, 'webhook_secret' => AA_WEBHOOK]);
    T::true(isset($f['api_secret']), 'cannot go live without the API secret');
    T::same(false, (bool) Gateways::row($gid)['is_active']);

    foreach (['short', 'has a space in it 123456'] as $bad) {
        T::true(isset($fields(['api_secret' => $bad])['api_secret']), $bad);
    }
    $view = Gateways::save($gid, ['is_active' => true, 'api_key' => AA_KEY, 'api_secret' => AA_SECRET, 'webhook_secret' => AA_WEBHOOK], $req, AA_ADMIN);
    T::same([true, true, 'headers'], [$view['is_active'], $view['has_api_secret'], $view['api_style']]);
    T::same(Crypto::mask(AA_SECRET), $view['api_secret_hint'], 'only a masked tail is shown');
    T::false(str_contains($view['api_secret_hint'], substr(AA_SECRET, 0, 12)));
    $json = json_encode($view);
    foreach ([AA_KEY, AA_SECRET, AA_WEBHOOK] as $secret) {
        T::false(str_contains($json, $secret), 'the admin view never carries a secret');
    }
    $enc = (string) Gateways::row($gid)['secrets_enc'];
    foreach ([AA_KEY, AA_SECRET, AA_WEBHOOK] as $secret) {
        T::false(str_contains($enc, $secret), 'secrets are encrypted at rest');
    }
    T::same(AA_SECRET, Crypto::decryptJson($enc)['api_secret']);
    $audit = (string) Db::val("SELECT GROUP_CONCAT(meta_json) FROM audit_logs WHERE action = 'gateway.saved'");
    T::contains('api_secret', $audit, 'the audit log names the field');
    T::false(str_contains($audit, AA_SECRET), 'and never the value');

    // a blank secret field keeps the stored one; switching the type keeps all secrets
    Gateways::save($gid, ['api_secret' => '', 'api_style' => 'bearer'], $req, AA_ADMIN);
    $view = Gateways::adminView(Gateways::row($gid));
    T::same(['bearer', true, true], [$view['api_style'], $view['has_api_secret'], $view['has_api_key']]);
    T::same('bearer', Gateways::adapter(Gateways::row($gid))->style());
    Gateways::save($gid, ['api_style' => 'headers'], $req, AA_ADMIN);
    T::same('headers', Gateways::adapter(Gateways::row($gid))->style());
    T::true(isset($fields(['api_style' => 'xml'])['api_style']), 'only the two known types');

    // the older setup: no secret needed once the type is "bearer"
    Db::exec("UPDATE payment_gateways SET secrets_enc = ?, is_active = 0 WHERE id = ?", [Crypto::encryptJson(['api_key' => AA_KEY, 'webhook_secret' => AA_WEBHOOK]), $gid]);
    Gateways::save($gid, ['api_style' => 'bearer', 'is_active' => true], $req, AA_ADMIN);
    T::same(true, (bool) Gateways::row($gid)['is_active']);
    aa_done();
});

T::test('alpha-api: an existing gateway with no chosen type keeps working as before; new or unfinished ones use the current API', function () {
    $key = ['api_key' => AA_KEY, 'webhook_secret' => AA_WEBHOOK];
    T::same('bearer', Gateways::style(['is_active' => 1], [], $key), 'live with only a key = the older setup an upgrade must not break');
    T::same('headers', Gateways::style(['is_active' => 0], [], $key), 'not live yet');
    T::same('headers', Gateways::style(['is_active' => 1], [], $key + ['api_secret' => AA_SECRET]), 'a secret means the current API');
    T::same('headers', Gateways::style(['is_active' => 0], [], []), 'brand new');
    T::same('bearer', Gateways::style(['is_active' => 0], ['api_style' => 'bearer'], $key), 'an explicit choice wins');
    T::same('headers', Gateways::style(['is_active' => 1], ['api_style' => 'headers'], $key), 'an explicit choice wins');
    T::same('headers', Gateways::style(['is_active' => 1], ['api_style' => 'junk'], $key + ['api_secret' => AA_SECRET]));
});
