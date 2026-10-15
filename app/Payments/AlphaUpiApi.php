<?php

declare(strict_types=1);

namespace App\Payments;

use AlphaUpi\AlphaUpiException;

/**
 * Client for the Alpha UPI REST API that authenticates every request with two headers — X-API-Key and X-API-Secret — and answers
 * {"status":"success","data":{…}}:
 *
 *   POST {base}/api/create-order   {amount, order_id, customer_name, callback_url?, description?, customer_mobile?}  → 201 data.payment_url
 *   POST {base}/api/check-status   {order_id}                                                                       → 200 data.payment_status
 *
 * (The older API that takes one Bearer key is served by the vendor SDK in app/Vendor/AlphaUpi; AlphaUpiGateway picks the client.)
 *
 * This class only does the transport: it never interprets the answer, and it hands back the HTTP status even for 4xx / 5xx so the
 * adapter can tell "wrong credentials" from "unknown order" from "this is not the API". Credentials never appear in an exception.
 */
final class AlphaUpiApi
{
    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly int $connectTimeout = 8,
        private readonly int $timeout = 20,
    ) {
        if (trim($baseUrl) === '') {
            throw new AlphaUpiException('The gateway base URL is not configured.');
        }
        if ($apiKey === '') {
            throw new AlphaUpiException('The gateway API key is not configured.');
        }
        if ($apiSecret === '') {
            throw new AlphaUpiException('The gateway API secret is not configured.');
        }
        if (!extension_loaded('curl')) {
            throw new AlphaUpiException('The cURL PHP extension is required.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{http:int, json:?array<string,mixed>, body:string} json is null when the answer is not a JSON object
     */
    public function post(string $path, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new AlphaUpiException('Could not encode the request as JSON.');
        }
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-Key: ' . $this->apiKey,
                'X-API-Secret: ' . $this->apiSecret,
                'User-Agent: SocialMediaBooster/1.0',
            ],
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE    => 1_000_000,
            // Never disable these.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            // The credentials are never part of the message.
            throw new AlphaUpiException(sprintf('Network error contacting Alpha UPI (%d): %s', $errno, $error));
        }
        $decoded = json_decode((string) $body, true);
        return ['http' => $http, 'json' => is_array($decoded) ? $decoded : null, 'body' => mb_substr((string) $body, 0, 2000)];
    }
}
