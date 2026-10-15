<?php
declare(strict_types=1);

use App\Controllers\PayController;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Payments\ConnectionTestResult;
use App\Payments\CreatePaymentRequest;
use App\Payments\CreatePaymentResult;
use App\Payments\Gateways;
use App\Payments\PaymentGateway;
use App\Payments\PaymentStatusResult;
use App\Payments\RefundRequest;
use App\Payments\RefundResult;
use App\Payments\WebhookEvent;
use App\Services\Orders;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\Qr;
use App\Support\Upi;

/**
 * The payment QR.
 *
 * The bug: the QR encoded the gateway's HOSTED CHECKOUT URL (https://alphaupi.site/pay/…). A UPI app cannot pay an https URL, and a camera
 * opens it as a web page that draws ANOTHER QR. The documented Alpha UPI API (create-order / check-status / webhook, and the vendor SDK)
 * returns only that hosted `payment_url` — no UPI intent, no QR string, no image — so for Alpha UPI there is no QR to show: the page sends
 * the customer to the hosted checkout with a button. A QR is drawn ONLY for a real `upi://pay` request for exactly this order and amount.
 */

const PQ_VPA = 'merchant.demo@okbizaxis';

/** A UPI payment request for $paise (as a gateway would build it). */
function pq_intent(int $paise, string $over = '', string $ref = 'SMBTEST0001'): string
{
    $base = 'upi://pay?pa=' . PQ_VPA . '&pn=' . rawurlencode('Social Media Booster') . '&am=' . Money::plain($paise) . '&cu=INR&tn=' . rawurlencode('Order ' . $ref) . '&tr=' . $ref;
    return $over !== '' ? $over : $base;
}

/**
 * A stand-in gateway whose create-order answer is chosen by the test (tests only: it is registered under its own provider key).
 * `$mode`: exact | wrong_amount | usd | https_intent | hosted_only | both | none
 */
final class PqGateway implements PaymentGateway
{
    public static string $mode = 'exact';
    public static string $status = PaymentStatusResult::PENDING;
    /** @var list<string> */
    public static array $created = [];

    public function provider(): string { return 'pq_test_gw'; }
    public function supportsRefunds(): bool { return false; }
    public function refundPayment(RefundRequest $request): RefundResult { return new RefundResult(supported: false, message: 'n/a'); }
    public function testConnection(): ConnectionTestResult { return new ConnectionTestResult(true, 'ok', 1, 'ok'); }
    public function handleWebhook(string $rawBody, array $headers): WebhookEvent { return new WebhookEvent(false, error: 'not used'); }

    public function createPayment(CreatePaymentRequest $r): CreatePaymentResult
    {
        self::$created[] = $r->gatewayOrderId;
        $hosted = 'https://gateway.example/pay/' . $r->gatewayOrderId;
        $exact = pq_intent($r->amountPaise, '', $r->gatewayOrderId);
        return match (self::$mode) {
            'exact'        => new CreatePaymentResult(true, paymentUrl: $hosted, upiIntent: $exact),
            'both'         => new CreatePaymentResult(true, paymentUrl: $hosted, upiIntent: $exact),
            'upi_only'     => new CreatePaymentResult(true, upiIntent: $exact),
            'wrong_amount' => new CreatePaymentResult(true, paymentUrl: $hosted, upiIntent: pq_intent($r->amountPaise + 100, '', $r->gatewayOrderId)),
            'usd'          => new CreatePaymentResult(true, paymentUrl: $hosted, upiIntent: str_replace('cu=INR', 'cu=USD', $exact)),
            'https_intent' => new CreatePaymentResult(true, paymentUrl: $hosted, upiIntent: $hosted),
            'hosted_only'  => new CreatePaymentResult(true, paymentUrl: $hosted),
            'wrong_only'   => new CreatePaymentResult(true, upiIntent: pq_intent($r->amountPaise + 100, '', $r->gatewayOrderId)),
            default        => new CreatePaymentResult(true),
        };
    }

    public function getPaymentStatus(string $gatewayOrderId): PaymentStatusResult
    {
        $paid = self::$status === PaymentStatusResult::SUCCESS;
        $amount = (int) Db::val('SELECT amount_paise FROM payments WHERE gateway_order_id = ?', [$gatewayOrderId]);
        return new PaymentStatusResult(true, self::$status, $paid ? $amount : null, $paid ? 'UTR' . substr(sha1($gatewayOrderId), 0, 10) : null, $paid ? 'upi' : null, currency: $paid ? 'INR' : null);
    }

    public function verifyPayment(string $gatewayOrderId, int $expectedAmountPaise): PaymentStatusResult { return $this->getPaymentStatus($gatewayOrderId); }
}

/** Fresh DB + the stand-in gateway as the active one. @return int gateway id */
function pq_setup(string $mode = 'exact'): int
{
    T::startMocks();
    T::freshDb();
    Gateways::register('pq_test_gw', static fn(): PaymentGateway => new PqGateway());
    PqGateway::$mode = $mode;
    PqGateway::$status = PaymentStatusResult::PENDING;
    PqGateway::$created = [];
    $gid = T::configureGateway();
    Db::update('payment_gateways', ['provider' => 'pq_test_gw'], 'id = ?', [$gid]);
    return $gid;
}

function pq_pay_page(string $ref): string
{
    return (new PayController())->show(Request::fake('GET', '/pay/' . $ref), ['ref' => $ref])->body;
}

function pq_qr(string $ref, bool $download = false): \App\Core\Response
{
    return (new PayController())->qr(Request::fake('GET', '/pay/' . $ref . '/qr.png', [], '', [], '203.0.113.9', $download ? ['download' => '1'] : []), ['ref' => $ref]);
}

/** Decode PNG bytes with an independent decoder (jsQR through node). null when node / jsqr is not installed. */
function pq_decode(string $png): ?string
{
    $root = dirname(__DIR__, 2);
    if (!is_file($root . '/node_modules/jsqr/package.json') || trim((string) shell_exec('node -v 2>/dev/null')) === '') {
        return null;
    }
    $f = sys_get_temp_dir() . '/smb-pq-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.png';
    file_put_contents($f, $png);
    $out = json_decode((string) shell_exec('node ' . escapeshellarg($root . '/tools/qa/qr-decode.mjs') . ' ' . escapeshellarg($f)), true);
    @unlink($f);
    return is_array($out) ? ($out[0] ?? '') : '';
}

// ═════════════════════════════ the UPI payment request ═════════════════════════════

T::test('upi: a well-formed UPI payment request is accepted and read exactly', function () {
    $r = Upi::parse('upi://pay?pa=merchant.demo@okbizaxis&pn=Social%20Media%20Booster&am=5.00&cu=INR&tn=Order%20SMB-ABCDEFGH&tr=SMBABCDEFGH');
    T::same('merchant.demo@okbizaxis', $r['payee']);
    T::same('Social Media Booster', $r['name']);
    T::same(500, $r['amount_paise']);
    T::same('SMBABCDEFGH', $r['reference']);
    T::same('Order SMB-ABCDEFGH', $r['note']);
    T::same(500, Upi::parse('upi://pay?pa=a.b@ybl&am=5')['amount_paise'], 'whole rupees, no cu (the specification default is INR)');
    T::same(9950, Upi::parse('upi://pay?pa=9876543210@ybl&am=99.5&cu=INR')['amount_paise']);
    T::same(123450, Upi::parse('upi://pay?pa=shop-1@paytm&am=1234.50&cu=INR&mc=5815&mode=02&purpose=00&url=https%3A%2F%2Fshop.example%2Fo%2F1')['amount_paise']);
    T::same(500, Upi::parse('upi://pay?pa=a%40ybl&am=5.00')['amount_paise'], 'an encoded @ is read');
    T::same(500, Upi::validateFor(pq_intent(500), 500)['amount_paise']);
});

T::test('upi: everything that is not a payment request for exactly this order is refused', function () {
    $bad = [
        'the hosted checkout URL (the old QR payload)' => 'https://alphaupi.site/pay/SMB4F2A9C1D0E7B3A5F61',
        'a hosted URL with upi in the query' => 'https://example.com/pay?pa=a@ybl&am=5.00',
        'empty' => '',
        'no amount (the customer would type it)' => 'upi://pay?pa=a@ybl&cu=INR',
        'zero' => 'upi://pay?pa=a@ybl&am=0',
        'zero with decimals' => 'upi://pay?pa=a@ybl&am=0.00',
        'negative' => 'upi://pay?pa=a@ybl&am=-5',
        'three decimals' => 'upi://pay?pa=a@ybl&am=5.001',
        'exponent' => 'upi://pay?pa=a@ybl&am=1e3',
        'thousands separator' => 'upi://pay?pa=a@ybl&am=1,000',
        'leading zeros' => 'upi://pay?pa=a@ybl&am=05.00',
        'US dollars' => 'upi://pay?pa=a@ybl&am=5.00&cu=USD',
        'lower-case currency' => 'upi://pay?pa=a@ybl&am=5.00&cu=inr',
        'no payee' => 'upi://pay?am=5.00&cu=INR',
        'payee without a handle' => 'upi://pay?pa=merchant&am=5.00',
        'payee with a space' => 'upi://pay?pa=mer%20chant@ybl&am=5.00',
        'payee that is a URL' => 'upi://pay?pa=http://evil.example&am=5.00',
        'the amount twice (which one would an app use?)' => 'upi://pay?pa=a@ybl&am=5.00&am=500.00',
        'the payee twice' => 'upi://pay?pa=a@ybl&pa=b@ybl&am=5.00',
        'an unknown parameter' => 'upi://pay?pa=a@ybl&am=5.00&redirect=http://evil.example',
        'upi collect instead of pay' => 'upi://collect?pa=a@ybl&am=5.00',
        'no query' => 'upi://pay',
        'a query with an empty pair' => 'upi://pay?pa=a@ybl&&am=5.00',
        'a pair without =' => 'upi://pay?pa=a@ybl&am',
        'an empty value' => 'upi://pay?pa=a@ybl&am=5.00&pn=',
        'upper-case scheme' => 'UPI://pay?pa=a@ybl&am=5.00',
        'a space' => 'upi://pay?pa=a@ybl&am=5.00&pn=Social Media',
        'a newline' => "upi://pay?pa=a@ybl&am=5.00\n",
        'a control character in a value' => 'upi://pay?pa=a@ybl&am=5.00&tn=a%00b',
        'a fragment' => 'upi://pay?pa=a@ybl&am=5.00#x',
        'a quote' => 'upi://pay?pa=a@ybl&am=5.00&tn=a"b',
        'angle brackets' => 'upi://pay?pa=a@ybl&am=5.00&tn=<script>',
        'a javascript: link' => 'javascript:alert(1)',
        'an insecure details link' => 'upi://pay?pa=a@ybl&am=5.00&url=http%3A%2F%2Fshop.example',
        'a bad merchant code' => 'upi://pay?pa=a@ybl&am=5.00&mc=12',
        'a far too long reference' => 'upi://pay?pa=a@ybl&am=5.00&tr=' . str_repeat('x', 36),
        'an absurd length' => 'upi://pay?pa=a@ybl&am=5.00&tn=' . str_repeat('x', 1000),
    ];
    foreach ($bad as $why => $uri) {
        T::same(null, Upi::parse($uri), "refused: $why");
    }
    T::same(null, Upi::parse(null));
    // it must be for exactly the amount of THIS payment
    T::same(null, Upi::validateFor(pq_intent(9900), 500), '₹99 request for a ₹5 payment');
    T::same(null, Upi::validateFor(pq_intent(500), 9900), '₹5 request for a ₹99 payment');
    T::same(null, Upi::validateFor(pq_intent(50), 500), '₹0.50 for ₹5');
    T::same(null, Upi::validateFor(pq_intent(5000), 500), '₹50 for ₹5');
});

// ═════════════════════════════ Alpha UPI: hosted checkout only ═════════════════════════════

T::test('qr: Alpha UPI returns only a hosted page, so NO QR is created — the page sends the customer to the checkout with a button', function () {
    pf_setup();
    $p = pf_place('ig-followers-50');
    T::true(str_starts_with((string) $p['payment']['payment_url'], 'http://127.0.0.1:4010/pay/SMB'), 'the hosted checkout page is stored as the payment link');
    T::same(null, $p['payment']['qr_payload'], 'nothing is stored as "what the QR encodes": there is no QR');

    $html = pq_pay_page($p['ref']);
    T::contains('data-mode="hosted"', $html);
    T::contains('PAY SECURELY', $html);
    T::contains('PAY ₹5 NOW', $html);
    T::contains('href="' . $p['payment']['payment_url'] . '"', $html, 'the button opens the gateway checkout');
    T::contains('target="_blank"', $html);
    T::contains('rel="noopener noreferrer"', $html);
    T::false(str_contains($html, 'id="qr-img"') || str_contains($html, '/qr.png') || str_contains($html, 'DOWNLOAD QR') || str_contains($html, 'SCAN AND PAY'), 'no QR image, no download link and no "scan" promise');
    T::contains('id="timer"', $html);
    T::contains('05:00', $html, 'the 5-minute timer stays');
    $e = T::throws(HttpException::class, fn() => pq_qr($p['ref']), 410);
    T::same('gone', $e->errorCode, 'the QR route has nothing to serve');
    T::same(null, Db::val('SELECT qr_payload FROM payments WHERE id = ?', [$p['payment']['id']]));
});

T::test('qr: a payment created BEFORE this fix (its QR payload is the hosted https URL) is shown as a hosted checkout, never as a QR', function () {
    pf_setup();
    $p = pf_place('ig-followers-50');
    Db::update('payments', ['qr_payload' => $p['payment']['payment_url']], 'id = ?', [$p['payment']['id']]);   // what the old code stored
    $html = pq_pay_page($p['ref']);
    T::contains('data-mode="hosted"', $html);
    T::false(str_contains($html, 'id="qr-img"'), 'the old payload is not drawn');
    T::throws(HttpException::class, fn() => pq_qr($p['ref']), 410);
});

// ═════════════════════════════ a gateway that does return a UPI payment request ═════════════════════════════

T::test('qr: a real UPI payment request for exactly this order IS the QR — the image encodes that string and nothing else', function () {
    pq_setup('exact');
    $p = pf_place('ig-followers-50');                    // ₹5
    $uri = (string) $p['payment']['qr_payload'];
    T::same(pq_intent(500, '', $p['payment']['gateway_order_id']), $uri, 'stored exactly as the gateway gave it');
    T::true(str_starts_with((string) $p['payment']['payment_url'], 'https://gateway.example/pay/'), 'the hosted page is kept as the link, but it is not what the QR carries');
    T::same(500, Upi::parse($uri)['amount_paise']);
    T::contains('cu=INR', $uri);

    $html = pq_pay_page($p['ref']);
    T::contains('data-mode="upi"', $html);
    T::contains('SCAN AND PAY', $html);
    T::contains('id="qr-img" src="', $html);
    T::contains('DOWNLOAD QR', $html);
    T::contains('OPEN UPI APP', $html);
    T::contains('href="' . htmlspecialchars($uri, ENT_QUOTES) . '"', $html, 'the app button opens the same UPI request');
    T::contains('merchant.demo@okbizaxis', $html, 'the payee is shown so the customer can check who is being paid');
    T::contains('Social Media Booster', $html);
    T::false(str_contains($html, 'gateway.example'), 'the hosted checkout URL is not on the page at all');

    $res = pq_qr($p['ref']);
    T::same(200, $res->status);
    T::same('image/png', $res->headers['Content-Type']);
    T::same($res->body, Qr::png($uri, 10, 4, 'M'), 'the image is the QR of that one string');
    $decoded = pq_decode($res->body);
    if ($decoded !== null) {
        T::same($uri, $decoded, 'an independent decoder reads back the UPI request — not an https page');
        T::true(str_starts_with($decoded, 'upi://pay?'));
        T::false(str_contains($decoded, 'https://'));
    }
    T::contains('attachment; filename="' . $p['order']['public_id'] . '-payment-qr.png"', pq_qr($p['ref'], true)->headers['Content-Disposition']);
});

T::test('qr: a gateway that only gives a UPI request (no hosted page) works; one that gives nothing usable fails safely', function () {
    pq_setup('upi_only');
    $p = pf_place('ig-followers-50');
    T::same(null, $p['payment']['payment_url']);
    T::same(pq_intent(500, '', $p['payment']['gateway_order_id']), $p['payment']['qr_payload']);
    T::contains('data-mode="upi"', pq_pay_page($p['ref']));

    foreach (['none', 'wrong_only'] as $i => $mode) {
        PqGateway::$mode = $mode;
        $e = T::throws(HttpException::class, fn() => pf_place('ig-followers-100k', 'https://www.instagram.com/jane/', ['visitor_id' => null, 'session_id' => null], '198.51.100.' . (140 + $i)), 503);
        T::same('payments_unavailable', $e->errorCode, $mode);
    }
    T::same(2, (int) Db::val("SELECT COUNT(*) FROM payments WHERE status = 'failed'"));
    T::contains('neither a payment page nor a valid UPI payment request', (string) Db::val("SELECT failure_reason FROM payments WHERE status = 'failed' AND failure_reason LIKE 'The gateway returned neither%' LIMIT 1"));
});

T::test('qr: a UPI request for another amount, in another currency, or that is really a web link is never stored or drawn', function () {
    foreach (['wrong_amount', 'usd', 'https_intent'] as $i => $mode) {
        pq_setup($mode);
        $p = pf_place('ig-followers-50');
        T::same(null, $p['payment']['qr_payload'], "$mode: not stored as a QR payload");
        $html = pq_pay_page($p['ref']);
        T::contains('data-mode="hosted"', $html, "$mode: falls back to the hosted checkout");
        T::false(str_contains($html, 'id="qr-img"'), "$mode: no QR");
        T::throws(HttpException::class, fn() => pq_qr($p['ref']), 410);
    }
});

T::test('qr: the stored request is re-checked against the payment\'s own amount on every use (a tampered row cannot show a QR for another amount)', function () {
    pq_setup('exact');
    $p = pf_place('ig-followers-50');
    T::same(200, pq_qr($p['ref'])->status);
    Db::update('payments', ['qr_payload' => pq_intent(1, '', 'X')], 'id = ?', [$p['payment']['id']]);                       // ₹0.01 request
    T::throws(HttpException::class, fn() => pq_qr($p['ref']), 410);
    T::contains('data-mode="hosted"', pq_pay_page($p['ref']));
    Db::update('payments', ['qr_payload' => 'upi://pay?pa=a@ybl&am=5.00&cu=USD'], 'id = ?', [$p['payment']['id']]);
    T::throws(HttpException::class, fn() => pq_qr($p['ref']), 410);
    Db::update('payments', ['qr_payload' => pq_intent(500, '', 'ORIGINAL')], 'id = ?', [$p['payment']['id']]);              // valid again
    T::same(200, pq_qr($p['ref'])->status);
});

T::test('qr: no QR after the session ends — expired, paid and failed payments serve nothing', function () {
    pq_setup('exact');
    $a = pf_place('ig-followers-50');
    T::same(200, pq_qr($a['ref'])->status);
    Db::update('payments', ['expires_at' => \App\Support\Clock::sql(\App\Support\Clock::now() - 5)], 'id = ?', [$a['payment']['id']]);
    T::throws(HttpException::class, fn() => pq_qr($a['ref']), 410);
    $html = pq_pay_page($a['ref']);
    T::false((bool) preg_match('/id="qr-img"[^>]*src=/', $html), 'the expired page ships no QR');
    T::contains('Payment session expired', $html);
    T::contains('Please do not pay for this order any more', $html);

    $b = pf_place('ig-followers-100k', 'https://www.instagram.com/jane/', ['visitor_id' => null, 'session_id' => null], '198.51.100.151');
    Db::update('payments', ['status' => 'failed'], 'id = ?', [$b['payment']['id']]);
    T::throws(HttpException::class, fn() => pq_qr($b['ref']), 410);
    Db::update('payments', ['status' => 'success'], 'id = ?', [$b['payment']['id']]);
    T::throws(HttpException::class, fn() => pq_qr($b['ref']), 410);
});

// ═════════════════════════════ paying is only ever confirmed by the server ═════════════════════════════

T::test('qr: scanning, opening or clicking never marks anything paid — only the gateway\'s own status does, once, for the exact amount', function () {
    pq_setup('exact');
    $p = pf_place('ig-followers-50');
    $html = pq_pay_page($p['ref']);
    T::false((bool) preg_match('/i have paid|i\'ve paid|payment done|mark as paid|confirm payment/i', $html), 'the page has no "I have paid" control');
    pq_qr($p['ref']);                                                                      // a view, a QR fetch and a poll change nothing
    $s = (new PayController())->status(Request::fake('GET', '/api/pay/' . $p['ref'] . '/status'), ['ref' => $p['ref']]);
    T::same('pending', json_decode($s->body, true)['state']);
    T::same('PENDING_PAYMENT', Orders::byId((int) $p['order']['id'])['status']);
    T::same('pending', Db::val('SELECT status FROM payments WHERE id = ?', [$p['payment']['id']]));

    PqGateway::$status = PaymentStatusResult::SUCCESS;                                    // the gateway's server-to-server answer
    $refresh = static function () use ($p): array {
        Db::exec('UPDATE payments SET last_polled_at = NULL WHERE id = ?', [$p['payment']['id']]);   // the per-payment poll throttle
        return PaymentService::refresh(Db::one('SELECT * FROM payments WHERE id = ?', [$p['payment']['id']]));
    };
    $r = $refresh();
    T::same('success', $r['payment']['status']);
    T::same('MANUAL_PENDING', Orders::byId((int) $p['order']['id'])['status']);
    // verified again and again, never twice
    $refresh();
    $refresh();
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM order_status_history WHERE order_id = ? AND to_status = 'PAID'", [$p['order']['id']]));
});

// ═════════════════════════════ migration 004 ═════════════════════════════

T::test('migration 004: the old "scan with your camera, a page opens" default text is replaced; a text an admin wrote is untouched', function () {
    T::freshDb();
    $sql = (string) file_get_contents(SMB_ROOT . '/database/migrations/004_payment_page_instructions.sql');
    $old = 'Scan the QR code with your phone camera. The secure payment page opens — pay with any UPI app. Keep this page open until your payment is confirmed.';
    $new = 'Pay with any UPI app. Keep this page open until your payment is confirmed.';
    T::same($new, \App\Services\Settings::defaults()['site.payment_instructions'], 'the code default is the new text');
    $run = static function () use ($sql): void {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) {
            Db::exec($stmt);
        }
    };
    $set = static fn(string $text) => Db::exec(
        'INSERT INTO admin_settings (k, v, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        ['site.payment_instructions', json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
    );
    $get = static fn(): string => (string) json_decode((string) Db::val("SELECT v FROM admin_settings WHERE k = 'site.payment_instructions'"), true);

    $set($old);
    $run();
    T::same($new, $get(), 'the stored old default becomes the new default');
    $set('Pay to the UPI ID shown, then wait here. Questions? WhatsApp us.');
    $run();
    T::same('Pay to the UPI ID shown, then wait here. Questions? WhatsApp us.', $get(), 'an admin\'s own wording is never touched');
    Db::exec("DELETE FROM admin_settings WHERE k = 'site.payment_instructions'");
    $run();
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM admin_settings WHERE k = 'site.payment_instructions'"), 'nothing is created where nothing was stored');
});
