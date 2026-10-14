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
 * Alpha UPI adapter, built on the vendor SDK in app/Vendor/AlphaUpi (used
 * unmodified).
 *
 * What the gateway gives us (from the SDK's contract):
 *   create-order        → a hosted payment page URL (there is no raw UPI/QR field);
 *   check-order-status  → PENDING | SUCCESS | FAILED | EXPIRED (+ amount, utr);
 *   webhook             → HMAC-SHA256(rawBody, webhookSecret) in X-Alpha-Signature,
 *                         JSON {event_id, order_id, status, amount, utr}, retried
 *                         until we answer 2xx;
 *   no refund API.
 *
 * Consequently the QR we show encodes the hosted payment URL: scanning it with
 * a phone camera opens the gateway's page where the customer pays with any UPI app.
 */
final class AlphaUpiGateway implements PaymentGateway
{
    public const PROVIDER = 'alpha_upi';

    /**
     * @param array<string,mixed> $config  base_url, provider_pref
     * @param array<string,mixed> $secrets api_key, webhook_secret
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

    private function client(): AlphaUpiClient
    {
        $key = (string) ($this->secrets['api_key'] ?? '');
        if ($key === '') {
            throw new AlphaUpiException('The gateway API key is not configured.');
        }
        // Re-checked on every use: DNS could have changed since the URL was saved.
        $base = NetGuard::assertSafeUrl((string) ($this->config['base_url'] ?? ''), 'gateway URL');
        return new AlphaUpiClient(['base_url' => $base, 'api_key' => $key, 'connect_timeout' => 8, 'timeout' => 20]);
    }

    public function createPayment(CreatePaymentRequest $request): CreatePaymentResult
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

        $url = (string) ($r['payment_url'] ?? '');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $allowHttp = Env::bool('ALLOW_PRIVATE_API_HOSTS', false);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !($scheme === 'https' || ($allowHttp && $scheme === 'http')) || strlen($url) > 1000) {
            Log::error('Alpha UPI returned an unusable payment_url');
            return new CreatePaymentResult(false, message: 'The gateway did not return a valid payment page URL.', code: 'bad_payment_url', raw: $raw);
        }
        return new CreatePaymentResult(true, paymentUrl: $url, qrPayload: $url, message: (string) ($r['message'] ?? ''), raw: $raw);
    }

    public function getPaymentStatus(string $gatewayOrderId): PaymentStatusResult
    {
        try {
            $r = $this->client()->checkOrderStatus($gatewayOrderId);
        } catch (AlphaUpiException | \InvalidArgumentException $e) {
            return new PaymentStatusResult(false, message: $e->getMessage());
        }
        $status = strtoupper((string) ($r['status'] ?? 'UNKNOWN'));
        if (!in_array($status, [PaymentStatusResult::PENDING, PaymentStatusResult::SUCCESS, PaymentStatusResult::FAILED, PaymentStatusResult::EXPIRED], true)) {
            $status = PaymentStatusResult::UNKNOWN;
        }
        return new PaymentStatusResult(
            reachable: true,
            status: $status,
            amountPaise: self::paise($r['amount'] ?? null),
            utr: self::str($r['utr'] ?? null, 100),
            method: self::str($r['provider'] ?? null, 24),
            // The gateway's paid_at has no documented time zone, so we do not rely on it for decisions.
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

    public function handleWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $signature = (string) ($headers['x-alpha-signature'] ?? '');
        $secret = (string) ($this->secrets['webhook_secret'] ?? '');
        if (!AlphaUpiClient::verifyWebhookSignature($rawBody, $signature, $secret)) {
            return new WebhookEvent(false, error: 'Invalid or missing signature.');
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return new WebhookEvent(true, error: 'The webhook body is not valid JSON.');
        }
        $status = strtoupper((string) ($data['status'] ?? ''));
        if (!in_array($status, [PaymentStatusResult::PENDING, PaymentStatusResult::SUCCESS, PaymentStatusResult::FAILED, PaymentStatusResult::EXPIRED], true)) {
            $status = PaymentStatusResult::UNKNOWN;
        }
        // The documented payload carries event_id; if a delivery ever lacks it, identical bodies still dedupe.
        $eventId = self::str($data['event_id'] ?? null, 120) ?? ('body:' . hash('sha256', $rawBody));
        return new WebhookEvent(
            signatureValid: true,
            eventId: $eventId,
            status: $status,
            gatewayOrderId: self::str($data['order_id'] ?? null, 64),
            amountPaise: self::paise($data['amount'] ?? null),
            utr: self::str($data['utr'] ?? null, 100),
            method: self::str($data['provider'] ?? null, 24),
            paidAt: null,
            payload: $this->sanitize($data),
        );
    }

    public function refundPayment(RefundRequest $request): RefundResult
    {
        return new RefundResult(
            supported: false,
            message: 'Alpha UPI has no refund API. Send the money back from your UPI app, then record the refund reference in the order.'
        );
    }

    public function testConnection(): ConnectionTestResult
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

    /** Keep only fields we understand; never persist anything key-like. */
    private function sanitize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (['status', 'code', 'message', 'event_id', 'order_id', 'amount', 'utr', 'provider', 'paid_at'] as $k) {
            if (array_key_exists($k, $raw) && is_scalar($raw[$k])) {
                $out[$k] = is_string($raw[$k]) ? mb_substr($raw[$k], 0, 300) : $raw[$k];
            }
        }
        if (isset($raw['result']) && is_array($raw['result'])) {
            foreach (['orderId', 'payment_url', 'status', 'amount', 'utr', 'provider', 'paid_at'] as $k) {
                if (isset($raw['result'][$k]) && is_scalar($raw['result'][$k])) {
                    $out['result'][$k] = mb_substr((string) $raw['result'][$k], 0, 500);
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
