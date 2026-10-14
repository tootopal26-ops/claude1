<?php

declare(strict_types=1);

namespace AlphaUpi;

/**
 * Alpha UPI Payment Gateway — official PHP client.
 *
 * Requires PHP 8.1+ and the cURL extension.
 * SSL verification is always on and cannot be disabled through this class.
 */
class AlphaUpiClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $connectTimeout;
    private int $timeout;

    /**
     * @param array{base_url:string, api_key:string, connect_timeout?:int, timeout?:int} $config
     */
    public function __construct(array $config)
    {
        if (empty($config['base_url'])) {
            throw new AlphaUpiException('base_url is required.');
        }
        if (empty($config['api_key'])) {
            throw new AlphaUpiException('api_key is required.');
        }
        if (!extension_loaded('curl')) {
            throw new AlphaUpiException('The cURL PHP extension is required.');
        }

        $this->baseUrl = rtrim($config['base_url'], '/');
        $this->apiKey = $config['api_key'];
        $this->connectTimeout = max(1, (int) ($config['connect_timeout'] ?? 10));
        $this->timeout = max(1, (int) ($config['timeout'] ?? 30));
    }

    /**
     * Creates an order and returns a hosted payment URL.
     *
     * Required: order_id, amount
     * Optional: customer_name, customer_mobile, redirect_url, remark, remark2, provider
     *
     * @param array<string, mixed> $params
     * @return array{success:bool, message:string, order_id?:string, payment_url?:string, raw:array}
     */
    public function createOrder(array $params): array
    {
        foreach (['order_id', 'amount'] as $required) {
            if (!isset($params[$required]) || $params[$required] === '') {
                throw new AlphaUpiException(sprintf('"%s" is required to create an order.', $required));
            }
        }

        if (!preg_match('/^\d+(\.\d{1,2})?$/', (string) $params['amount'])) {
            throw new AlphaUpiException('"amount" must be a number with at most two decimal places.');
        }

        if (isset($params['provider']) && !in_array($params['provider'], ['bharatpe', 'paytm'], true)) {
            throw new AlphaUpiException('"provider", when supplied, must be "bharatpe" or "paytm".');
        }

        $payload = [
            'order_id' => (string) $params['order_id'],
            'amount' => (string) $params['amount'],
            'customer_name' => (string) ($params['customer_name'] ?? ''),
            'customer_mobile' => (string) ($params['customer_mobile'] ?? ''),
            'redirect_url' => (string) ($params['redirect_url'] ?? ''),
            'remark1' => (string) ($params['remark'] ?? $params['remark1'] ?? ''),
            'remark2' => (string) ($params['remark2'] ?? ''),
        ];

        if (isset($params['provider'])) {
            $payload['provider'] = (string) $params['provider'];
        }

        $response = $this->post('/api/create-order', $payload);

        return [
            'success' => (bool) ($response['status'] ?? false),
            // Machine-readable reason when the gateway refuses, e.g.
            // SUBSCRIPTION_QUOTA_EXHAUSTED or SUBSCRIPTION_EXPIRED.
            'code' => $response['code'] ?? null,
            'message' => (string) ($response['message'] ?? ''),
            'order_id' => $response['result']['orderId'] ?? null,
            'payment_url' => $response['result']['payment_url'] ?? null,
            // Present on quota refusals: ['used' => int, 'limit' => int, 'remaining' => int]
            'usage' => $response['usage'] ?? null,
            'raw' => $response,
        ];
    }

    /**
     * True when a createOrder() result was refused because the plan's
     * successful-payment quota is used up. Renewing the plan clears it.
     *
     * @param array<string, mixed> $result A createOrder() return value.
     */
    public static function isQuotaExhausted(array $result): bool
    {
        return ($result['success'] ?? true) === false
            && ($result['code'] ?? null) === 'SUBSCRIPTION_QUOTA_EXHAUSTED';
    }

    /**
     * Looks up the current status of one of your orders.
     *
     * @return array{success:bool, status:string, message:string, amount?:string, utr?:string, provider?:string, paid_at?:string, raw:array}
     */
    public function checkOrderStatus(string $orderId): array
    {
        if ($orderId === '') {
            throw new AlphaUpiException('"order_id" is required.');
        }

        $response = $this->post('/api/check-order-status', ['order_id' => $orderId]);
        $result = $response['result'] ?? [];

        return [
            'success' => (bool) ($response['status'] ?? false),
            'status' => (string) ($result['status'] ?? 'UNKNOWN'),
            'message' => (string) ($response['message'] ?? ''),
            'amount' => $result['amount'] ?? null,
            'utr' => $result['utr'] ?? null,
            'provider' => $result['provider'] ?? null,
            'paid_at' => $result['paid_at'] ?? null,
            'raw' => $response,
        ];
    }

    /**
     * Convenience wrapper: creates an order whose purpose is a shareable link.
     *
     * @param array<string, mixed> $params
     */
    public function createPaymentLink(array $params): array
    {
        $params['order_id'] = $params['order_id'] ?? ('LINK' . bin2hex(random_bytes(5)));

        return $this->createOrder($params);
    }

    /**
     * Verifies an incoming webhook signature (header: X-Alpha-Signature).
     * Always compare with hash_equals to avoid timing leaks.
     */
    public static function verifyWebhookSignature(string $rawBody, string $signatureHeader, string $webhookSecret): bool
    {
        if ($rawBody === '' || $signatureHeader === '' || $webhookSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, trim($signatureHeader));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new AlphaUpiException('Could not encode the request payload as JSON.');
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'User-Agent: AlphaUpiPHP/1.0',
            ],
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            // Never disable these.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            // The API key is never included in the exception message.
            throw new AlphaUpiException(sprintf('Network error contacting Alpha UPI (%d): %s', $errno, $error));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new AlphaUpiException(sprintf('Alpha UPI returned a non-JSON response (HTTP %d).', $httpCode));
        }

        return $decoded;
    }
}
