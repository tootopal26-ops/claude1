<?php

namespace App\Domain\Payments;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adapter for the Alpha UPI / Lola Pay gateway, built against its own
 * documented REST API (base URL https://alphaupi.site/api): POST
 * /create-order, POST /check-status, and HMAC-SHA256 webhook signatures.
 *
 * This gateway has no sandbox — "every created checkout can accept a real
 * payment" per its own docs — so this adapter is complete to spec but has
 * never been exercised against the live endpoint; do that deliberately,
 * with a small real amount, before relying on it.
 *
 * The provider's docs are internally inconsistent about the webhook
 * signature header name (prose says "X-Lola Pay-Signature", which isn't
 * even a legal HTTP header name since it contains a space; the executable
 * PHP sample reads "X-PayIndia-Signature" instead). $webhookSignatureHeader
 * is therefore admin-configurable (see PaymentProviderConfig) rather than
 * hardcoded, so it can be corrected from a real webhook delivery without a
 * deploy.
 */
class AlphaUpiAdapter implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $webhookSecret,
        private readonly string $webhookSignatureHeader,
    ) {}

    public function providerName(): string
    {
        return 'alpha_upi';
    }

    public function createOrder(
        string $orderId,
        string $amount,
        string $customerName,
        ?string $customerMobile = null,
        ?string $description = null,
    ): array {
        $response = $this->client()->post('/create-order', array_filter([
            'amount' => number_format((float) $amount, 2, '.', ''),
            'order_id' => $orderId,
            'customer_name' => $customerName,
            'customer_mobile' => $customerMobile,
            'description' => $description,
        ], fn ($value) => $value !== null));

        $body = $this->decodeOrFail($response, 'create-order');
        $data = $body['data'] ?? [];

        if (! isset($data['payment_url'], $data['token'])) {
            throw new PaymentGatewayException('Alpha UPI create-order response is missing payment_url/token.');
        }

        return [
            'payment_url' => $data['payment_url'],
            'token' => $data['token'],
            'expires_at' => $data['expires_at'] ?? null,
            'raw' => $body,
        ];
    }

    public function checkStatus(string $orderId): array
    {
        $response = $this->client()->post('/check-status', ['order_id' => $orderId]);

        $body = $this->decodeOrFail($response, 'check-status');
        $data = $body['data'] ?? [];

        if (! isset($data['payment_status'])) {
            throw new PaymentGatewayException('Alpha UPI check-status response is missing payment_status.');
        }

        return [
            'payment_status' => $data['payment_status'],
            'amount' => isset($data['amount']) ? number_format((float) $data['amount'], 2, '.', '') : null,
            'utr' => $data['utr'] ?? null,
            'gateway_txn_id' => $data['gateway_txn_id'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
            'raw' => $body,
        ];
    }

    public function webhookSignatureHeader(): string
    {
        return $this->webhookSignatureHeader;
    }

    public function verifyWebhookSignature(string $rawPayload, string $signature): bool
    {
        if ($signature === '' || $this->webhookSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-API-Key' => $this->apiKey,
                'X-API-Secret' => $this->apiSecret,
            ])
            ->acceptJson()
            ->timeout(15);
    }

    private function decodeOrFail(Response $response, string $endpoint): array
    {
        if ($response->failed()) {
            throw new PaymentGatewayException(
                "Alpha UPI {$endpoint} request failed with status {$response->status()}: {$response->body()}",
            );
        }

        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'success') {
            throw new PaymentGatewayException(
                "Alpha UPI {$endpoint} returned an unexpected response: {$response->body()}",
            );
        }

        return $body;
    }
}
