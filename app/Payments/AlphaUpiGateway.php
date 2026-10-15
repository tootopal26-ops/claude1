<?php

declare(strict_types=1);

namespace App\Payments;

use AlphaUpi\AlphaUpiClient;
use AlphaUpi\AlphaUpiException;
use App\Core\Env;
use App\Core\Log;
use App\Support\Money;
use App\Support\NetGuard;

/**
 * Alpha UPI adapter. It speaks the two flavours of the Alpha UPI API (config `api_style`):
 *
 *   headers  the current API (alphaupi.site): X-API-Key + X-API-Secret request headers,
 *              POST /api/create-order  → 201 {status:"success", data:{payment_url, order_id, …}}
 *              POST /api/check-status  → 200 {status:"success", data:{payment_status:"success"|…, amount, utr, …}}
 *              served by AlphaUpiApi;
 *   bearer   the older API of the vendor SDK in app/Vendor/AlphaUpi (used unmodified): Authorization: Bearer <key>,
 *              /api/create-order and /api/check-order-status, {status:true, result:{…}}.
 *
 * Both give us a hosted payment page URL (there is no raw UPI / QR field), so the QR we show encodes that URL: scanning it
 * opens the gateway's page where the customer pays with any UPI app.
 *
 * Webhooks are the same in both: HMAC-SHA256(rawBody, webhookSecret) as a hex digest in a signature header, JSON body. The header
 * name differs between gateway versions (X-Alpha-Signature, X-PayIndia-Signature, …) and so does the payload
 * ({event_id, order_id, status:"SUCCESS", …} vs {event:"payment.success", status:"success", order_id, amount, utr}), so the parser
 * accepts every variant; the HMAC is what authenticates a delivery, not the header's name. No refund API.
 */
final class AlphaUpiGateway implements PaymentGateway
{
    public const PROVIDER = 'alpha_upi';
    public const STYLE_HEADERS = 'headers';
    public const STYLE_BEARER = 'bearer';

    /** Header names a signature may arrive in, best guess first; any other header with "signature" in its name is tried afterwards. */
    private const SIGNATURE_HEADERS = ['x-alpha-signature', 'x-payindia-signature', 'x-alphaupi-signature', 'x-lola-pay-signature', 'x-lolapay-signature', 'x-webhook-signature', 'x-signature'];

    /**
     * @param array<string,mixed> $config  base_url, api_style (headers|bearer), provider_pref (bearer only)
     * @param array<string,mixed> $secrets api_key, api_secret (headers only), webhook_secret
     */
    public function __construct(private readonly array $config, private readonly array $secrets)
    {
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function style(): string
    {
        return ($this->config['api_style'] ?? self::STYLE_BEARER) === self::STYLE_HEADERS ? self::STYLE_HEADERS : self::STYLE_BEARER;
    }

    private function usesHeaders(): bool
    {
        return $this->style() === self::STYLE_HEADERS;
    }

    /** Re-checked on every use: DNS could have changed since the URL was saved. */
    private function baseUrl(): string
    {
        return NetGuard::assertSafeUrl((string) ($this->config['base_url'] ?? ''), 'gateway URL');
    }

    private function client(): AlphaUpiClient
    {
        $key = (string) ($this->secrets['api_key'] ?? '');
        if ($key === '') {
            throw new AlphaUpiException('The gateway API key is not configured.');
        }
        return new AlphaUpiClient(['base_url' => $this->baseUrl(), 'api_key' => $key, 'connect_timeout' => 8, 'timeout' => 20]);
    }

    private function api(): AlphaUpiApi
    {
        return new AlphaUpiApi($this->baseUrl(), (string) ($this->secrets['api_key'] ?? ''), (string) ($this->secrets['api_secret'] ?? ''));
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function createPayment(CreatePaymentRequest $request): CreatePaymentResult
    {
        return $this->usesHeaders() ? $this->createViaHeaders($request) : $this->createViaSdk($request);
    }

    private function createViaHeaders(CreatePaymentRequest $request): CreatePaymentResult
    {
        $description = trim($request->remark1 . ($request->remark2 !== '' ? ' - ' . $request->remark2 : ''));   // plain ASCII separator: some gateways store text in latin1
        $payload = [
            'amount'        => sprintf('%d.%02d', intdiv($request->amountPaise, 100), $request->amountPaise % 100),
            'order_id'      => $request->gatewayOrderId,
            'customer_name' => $request->customerName !== '' ? $request->customerName : 'Customer',   // required by the API
            'callback_url'  => $request->redirectUrl,
        ];
        if ($description !== '') {
            $payload['description'] = mb_substr($description, 0, 120);
        }
        if ($request->customerMobile !== '') {
            $payload['customer_mobile'] = $request->customerMobile;
        }
        try {
            $res = $this->api()->post('/api/create-order', $payload);
        } catch (AlphaUpiException | \InvalidArgumentException $e) {
            Log::warning('Alpha UPI create-order failed', ['error' => $e->getMessage()]);
            return new CreatePaymentResult(false, message: $e->getMessage(), code: 'gateway_unreachable', retryable: true);
        }
        $j = $res['json'];
        if ($j === null) {
            $msg = $this->notTheApi($res['http']);
            Log::error('Alpha UPI create-order: not a JSON answer', ['http' => $res['http']]);
            return new CreatePaymentResult(false, message: $msg, code: 'gateway_bad_response', retryable: $res['http'] >= 500);
        }
        $raw = $this->sanitize($j);
        if ($res['http'] < 200 || $res['http'] >= 300 || !self::isSuccess($j['status'] ?? null)) {
            $msg = self::errorText($j) ?? ('The gateway refused the order (HTTP ' . $res['http'] . ').');
            Log::error('Alpha UPI refused to create an order', ['http' => $res['http'], 'message' => $msg]);
            $code = in_array($res['http'], [401, 403], true) ? 'gateway_auth' : 'gateway_refused';
            return new CreatePaymentResult(false, message: $msg, code: $code, retryable: $res['http'] >= 500, raw: $raw);
        }
        $data = is_array($j['data'] ?? null) ? $j['data'] : [];
        return $this->paymentUrlResult((string) ($data['payment_url'] ?? ''), (string) ($j['message'] ?? ''), $raw);
    }

    private function createViaSdk(CreatePaymentRequest $request): CreatePaymentResult
    {
        try {
            $params = [
                'order_id'        => $request->gatewayOrderId,
                'amount'          => Money::plain($request->amountPaise),
                'customer_name'   => $request->customerName,
                'customer_mobile' => $request->customerMobile,
                'redirect_url'    => $request->redirectUrl,
                'remark'          => $request->remark1,
                'remark2'         => $request->remark2,
            ];
            $pref = (string) ($this->config['provider_pref'] ?? 'auto');
            if (in_array($pref, ['bharatpe', 'paytm'], true)) {
                $params['provider'] = $pref;
            }
            $r = $this->client()->createOrder($params);
        } catch (AlphaUpiException | \InvalidArgumentException $e) {
            Log::warning('Alpha UPI create-order failed', ['error' => $e->getMessage()]);
            return new CreatePaymentResult(false, message: $e->getMessage(), code: 'gateway_unreachable', retryable: true);
        }

        $raw = $this->sanitize($r['raw'] ?? []);
        if (!$r['success']) {
            $quota = AlphaUpiClient::isQuotaExhausted($r);
            Log::error('Alpha UPI refused to create an order', ['code' => $r['code'] ?? null, 'message' => $r['message'] ?? '', 'quota' => $quota]);
            return new CreatePaymentResult(false, message: (string) ($r['message'] ?? 'The gateway refused the order.'), code: (string) ($r['code'] ?? 'gateway_refused'), retryable: false, raw: $raw);
        }
        return $this->paymentUrlResult((string) ($r['payment_url'] ?? ''), (string) ($r['message'] ?? ''), $raw);
    }

    /** The hosted payment page must be an https URL (http only against a local mock in development). */
    private function paymentUrlResult(string $url, string $message, array $raw): CreatePaymentResult
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $allowHttp = Env::bool('ALLOW_PRIVATE_API_HOSTS', false);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !($scheme === 'https' || ($allowHttp && $scheme === 'http')) || strlen($url) > 1000) {
            Log::error('Alpha UPI returned an unusable payment_url');
            return new CreatePaymentResult(false, message: 'The gateway did not return a valid payment page URL.', code: 'bad_payment_url', raw: $raw);
        }
        return new CreatePaymentResult(true, paymentUrl: $url, qrPayload: $url, message: $message, raw: $raw);
    }

    // ── status ───────────────────────────────────────────────────────────────

    public function getPaymentStatus(string $gatewayOrderId): PaymentStatusResult
    {
        return $this->usesHeaders() ? $this->statusViaHeaders($gatewayOrderId) : $this->statusViaSdk($gatewayOrderId);
    }

    private function statusViaHeaders(string $gatewayOrderId): PaymentStatusResult
    {
        try {
            $res = $this->api()->post('/api/check-status', ['order_id' => $gatewayOrderId]);
        } catch (AlphaUpiException | \InvalidArgumentException $e) {
            return new PaymentStatusResult(false, message: $e->getMessage());
        }
        $j = $res['json'];
        // Not the API, a gateway outage or refused credentials: we cannot tell what happened to the payment, so "try again later".
        if ($j === null || $res['http'] >= 500 || in_array($res['http'], [401, 403, 429], true)) {
            return new PaymentStatusResult(false, message: $j === null ? $this->notTheApi($res['http']) : (self::errorText($j) ?? 'The gateway answered HTTP ' . $res['http'] . '.'));
        }
        $d = is_array($j['data'] ?? null) ? $j['data'] : [];
        if (!self::isSuccess($j['status'] ?? null) || $d === []) {
            // e.g. "Order not found": reachable, but nothing to act on.
            return new PaymentStatusResult(true, message: self::errorText($j) ?? '', raw: $this->sanitize($j));
        }
        return new PaymentStatusResult(
            reachable: true,
            status: self::mapStatus($d['payment_status'] ?? $d['status'] ?? null),
            amountPaise: self::paise($d['amount'] ?? null),
            utr: self::str($d['utr'] ?? null, 100),
            method: self::str($d['provider'] ?? $d['payment_method'] ?? null, 24),
            // The gateway's paid_at has no documented time zone, so we do not rely on it for decisions.
            paidAt: null,
            message: (string) ($j['message'] ?? ''),
            raw: $this->sanitize($j),
        );
    }

    private function statusViaSdk(string $gatewayOrderId): PaymentStatusResult
    {
        try {
            $r = $this->client()->checkOrderStatus($gatewayOrderId);
        } catch (AlphaUpiException | \InvalidArgumentException $e) {
            return new PaymentStatusResult(false, message: $e->getMessage());
        }
        return new PaymentStatusResult(
            reachable: true,
            status: self::mapStatus($r['status'] ?? null),
            amountPaise: self::paise($r['amount'] ?? null),
            utr: self::str($r['utr'] ?? null, 100),
            method: self::str($r['provider'] ?? null, 24),
            paidAt: null,
            message: (string) ($r['message'] ?? ''),
            raw: $this->sanitize($r['raw'] ?? []),
        );
    }

    public function verifyPayment(string $gatewayOrderId, int $expectedAmountPaise): PaymentStatusResult
    {
        // The status lookup is the verification; the caller compares amountPaise with the expected amount.
        return $this->getPaymentStatus($gatewayOrderId);
    }

    // ── webhook ──────────────────────────────────────────────────────────────

    public function handleWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $secret = (string) ($this->secrets['webhook_secret'] ?? '');
        if (!self::signatureMatches($rawBody, $headers, $secret)) {
            return new WebhookEvent(false, error: 'Invalid or missing signature.');
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return new WebhookEvent(true, error: 'The webhook body is not valid JSON.');
        }
        // Some gateway versions nest the transaction under "data"; top-level fields win.
        $d = $data + (is_array($data['data'] ?? null) ? $data['data'] : []);
        // The legacy payload carries event_id; the current one has none, so identical redeliveries are recognised by their body.
        $eventId = self::str($d['event_id'] ?? null, 120) ?? ('body:' . hash('sha256', $rawBody));
        return new WebhookEvent(
            signatureValid: true,
            eventId: $eventId,
            status: self::mapStatus($d['status'] ?? $d['payment_status'] ?? null, $d['event'] ?? null),
            gatewayOrderId: self::str($d['order_id'] ?? null, 64),
            amountPaise: self::paise($d['amount'] ?? null),
            utr: self::str($d['utr'] ?? null, 100),
            method: self::str($d['provider'] ?? $d['payment_method'] ?? null, 24),
            paidAt: null,
            payload: $this->sanitize($d),
        );
    }

    /**
     * HMAC-SHA256 of the raw body as lower-case hex, in any of the usual signature headers. The digest — not the header's name — is
     * what proves the sender knows the webhook secret, so trying every header that mentions "signature" costs no security; the digest
     * itself must match exactly (no prefix, no other case).
     *
     * @param array<string,string> $headers lower-cased names
     */
    private static function signatureMatches(string $rawBody, array $headers, string $secret): bool
    {
        if ($rawBody === '' || $secret === '') {
            return false;
        }
        $candidates = [];
        foreach (self::SIGNATURE_HEADERS as $name) {
            if (isset($headers[$name])) {
                $candidates[] = $headers[$name];
            }
        }
        foreach ($headers as $name => $value) {
            if (str_contains((string) $name, 'signature') && !in_array($name, self::SIGNATURE_HEADERS, true)) {
                $candidates[] = $value;
            }
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        foreach ($candidates as $c) {
            $c = trim((string) $c);                  // the exact lower-case hex digest — no prefix, no other case
            if ($c !== '' && hash_equals($expected, $c)) {
                return true;
            }
        }
        return false;
    }

    public function refundPayment(RefundRequest $request): RefundResult
    {
        return new RefundResult(
            supported: false,
            message: 'Alpha UPI has no refund API. Send the money back from your UPI app, then record the refund reference in the order.'
        );
    }

    // ── connection test ──────────────────────────────────────────────────────

    public function testConnection(): ConnectionTestResult
    {
        return $this->usesHeaders() ? $this->testViaHeaders() : $this->testViaSdk();
    }

    private function testViaHeaders(): ConnectionTestResult
    {
        $t0 = microtime(true);
        $ms = static fn(): int => (int) round((microtime(true) - $t0) * 1000);
        try {
            // A lookup of an order that cannot exist: read-only, consumes no plan quota, creates nothing.
            $res = $this->api()->post('/api/check-status', ['order_id' => 'SMB-CONNECTION-TEST-' . bin2hex(random_bytes(4))]);
        } catch (AlphaUpiException | \InvalidArgumentException $e) {
            return new ConnectionTestResult(false, 'Could not reach the gateway: ' . $e->getMessage(), $ms(), 'error');
        }
        $j = $res['json'];
        if ($j === null) {
            return new ConnectionTestResult(false, 'Could not reach the gateway: ' . $this->notTheApi($res['http']), $ms(), 'error');
        }
        $msg = self::errorText($j) ?? '';
        if (in_array($res['http'], [401, 403], true) || preg_match('/(api[\s_-]?(key|secret)|credential|unauthori[sz]ed|forbidden|authenticat|invalid token|access denied|permission)/i', $msg)) {
            return new ConnectionTestResult(false, 'The gateway rejected the API key or API secret' . ($msg !== '' ? ': ' . rtrim($msg, '.!') : '') . '. Check both values in your Alpha UPI dashboard.', $ms(), 'error');
        }
        if ($res['http'] >= 500) {
            return new ConnectionTestResult(false, 'The gateway reported a server error (HTTP ' . $res['http'] . ')' . ($msg !== '' ? ': ' . $msg : '.'), $ms(), 'error');
        }
        if ($res['http'] === 404 && !preg_match('/(order|transaction|payment)/i', $msg)) {
            // "order not found" is what an unknown test order should answer; a bare "not found" means this address has no such endpoint
            return new ConnectionTestResult(false, 'Could not reach the gateway: ' . $this->notTheApi(404), $ms(), 'error');
        }
        if (self::isSuccess($j['status'] ?? null)) {
            return new ConnectionTestResult(true, 'Connected. The gateway is reachable and accepted the API key and secret.', $ms(), 'ok');
        }
        return new ConnectionTestResult(
            true,
            'The gateway is reachable and did not refuse the credentials. It answered the test lookup with “' . ($msg !== '' ? $msg : 'no message') . '”, which is expected for an unknown order; '
            . 'place a real ₹5 test order to be sure everything works.',
            $ms(),
            'warning'
        );
    }

    private function testViaSdk(): ConnectionTestResult
    {
        $t0 = microtime(true);
        $ms = static fn(): int => (int) round((microtime(true) - $t0) * 1000);
        try {
            // A lookup of an order that cannot exist: read-only, consumes no plan quota, creates nothing.
            $r = $this->client()->checkOrderStatus('SMB-CONNECTION-TEST-' . bin2hex(random_bytes(4)));
        } catch (AlphaUpiException | \InvalidArgumentException $e) {
            return new ConnectionTestResult(false, 'Could not reach the gateway: ' . $e->getMessage(), $ms(), 'error');
        }
        $msg = (string) ($r['message'] ?? '');
        if (preg_match('/(api[\s_-]?key|unauthori[sz]ed|forbidden|authenticat|bearer|invalid token|access denied|permission)/i', $msg)) {
            return new ConnectionTestResult(false, 'The gateway rejected the API key: ' . $msg, $ms(), 'error');
        }
        if ($r['success'] || $r['status'] !== 'UNKNOWN') {
            return new ConnectionTestResult(true, 'Connected. The gateway is reachable and accepted the API key.', $ms(), 'ok');
        }
        return new ConnectionTestResult(
            true,
            'The gateway is reachable. It answered the test lookup with “' . ($msg !== '' ? $msg : 'no message') . '”, which is expected for an unknown order; '
            . 'the API key could not be independently confirmed, so place a real test order to be sure.',
            $ms(),
            'warning'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** What to tell the admin when the address answered with something that is not the Alpha UPI API (a web page, a 404 …). */
    private function notTheApi(int $http): string
    {
        $host = (string) parse_url((string) ($this->config['base_url'] ?? ''), PHP_URL_HOST);
        if ($http >= 500) {
            return 'The gateway answered with a server error (HTTP ' . $http . ') instead of JSON. Try again in a moment.';
        }
        return 'The address' . ($host !== '' ? ' ' . $host : '') . ' answered with a web page (HTTP ' . $http . '), not with the Alpha UPI API. '
            . 'Check the Gateway base URL: it should be the API host itself (for Alpha UPI: https://alphaupi.site, without /api).';
    }

    /** "success" (current API) or true (older API). */
    private static function isSuccess(mixed $status): bool
    {
        return $status === true || (is_string($status) && strtolower(trim($status)) === 'success');
    }

    /** The gateway's own words for a refusal: current API `error`, older API `message`. */
    private static function errorText(array $j): ?string
    {
        foreach (['error', 'message'] as $k) {
            if (isset($j[$k]) && is_string($j[$k]) && trim($j[$k]) !== '') {
                return mb_substr(trim($j[$k]), 0, 300);
            }
        }
        return null;
    }

    /**
     * Gateway wording → ours. Only unmistakable words count as paid: an unfamiliar status is UNKNOWN and changes nothing.
     * `$event` ("payment.success") is consulted only when there is no status at all.
     */
    public static function mapStatus(mixed $status, mixed $event = null): string
    {
        $s = is_scalar($status) ? strtolower(trim((string) $status)) : '';
        if ($s === '' && is_string($event)) {
            $e = strtolower($event);
            $s = str_contains($e, 'success') ? 'success' : (str_contains($e, 'fail') ? 'failed' : (str_contains($e, 'expire') ? 'expired' : ''));
        }
        return match (true) {
            in_array($s, ['success', 'successful', 'paid', 'completed'], true) => PaymentStatusResult::SUCCESS,
            in_array($s, ['pending', 'created', 'initiated', 'processing', 'open', 'unpaid', 'waiting'], true) => PaymentStatusResult::PENDING,
            in_array($s, ['failed', 'failure', 'declined', 'rejected', 'cancelled', 'canceled'], true) => PaymentStatusResult::FAILED,
            in_array($s, ['expired', 'timeout', 'timed_out'], true) => PaymentStatusResult::EXPIRED,
            default => PaymentStatusResult::UNKNOWN,
        };
    }

    /** Keep only fields we understand (no customer details, nothing key-like): this is what gets stored. */
    private function sanitize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (['status', 'code', 'message', 'error', 'event', 'event_id', 'order_id', 'amount', 'utr', 'provider', 'paid_at'] as $k) {
            if (array_key_exists($k, $raw) && is_scalar($raw[$k])) {
                $out[$k] = is_string($raw[$k]) ? mb_substr($raw[$k], 0, 300) : $raw[$k];
            }
        }
        foreach (['result' => ['orderId', 'payment_url', 'status', 'amount', 'utr', 'provider', 'paid_at'],
                  'data'   => ['order_id', 'payment_url', 'amount', 'currency', 'payment_status', 'utr', 'provider', 'payment_method', 'gateway_txn_id', 'paid_at', 'expires_at', 'created_at']] as $block => $keys) {
            if (isset($raw[$block]) && is_array($raw[$block])) {
                foreach ($keys as $k) {
                    if (isset($raw[$block][$k]) && is_scalar($raw[$block][$k])) {
                        $out[$block][$k] = mb_substr((string) $raw[$block][$k], 0, 500);
                    }
                }
            }
        }
        if (isset($raw['usage']) && is_array($raw['usage'])) {
            $out['usage'] = array_intersect_key($raw['usage'], array_flip(['used', 'limit', 'remaining']));
        }
        return $out;
    }

    private static function paise(mixed $v): ?int
    {
        if ($v === null || $v === '' || !is_scalar($v)) {
            return null;
        }
        try {
            return Money::parseRupees(is_string($v) || is_int($v) || is_float($v) ? $v : (string) $v);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function str(mixed $v, int $max): ?string
    {
        if (!is_scalar($v) || (string) $v === '') {
            return null;
        }
        return mb_substr(trim((string) $v), 0, $max);
    }
}
