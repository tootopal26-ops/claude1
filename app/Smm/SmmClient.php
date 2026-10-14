<?php

declare(strict_types=1);

namespace App\Smm;

use App\Core\Env;
use App\Support\NetGuard;

/**
 * Client for the de-facto standard "SMM panel API v2" that almost every panel
 * exposes: one POST endpoint, form-encoded, `key` + `action` parameters.
 *
 *   action=add      service, link, quantity      → {"order": 23501}
 *   action=status   order                        → {"charge","start_count","status","remains","currency"}
 *   action=balance                               → {"balance","currency"}
 *   action=services                              → [{service,name,rate,min,max,…}, …]
 *
 * All calls run server-side; the API key never reaches a browser or a log.
 */
final class SmmClient
{
    public function __construct(private readonly string $apiUrl, private readonly string $apiKey)
    {
    }

    public function balance(): SmmResponse
    {
        return $this->call(['action' => 'balance'], false);
    }

    public function services(): SmmResponse
    {
        return $this->call(['action' => 'services'], false, 8_000_000);
    }

    public function add(string $serviceId, string $link, int $quantity): SmmResponse
    {
        return $this->call(['action' => 'add', 'service' => $serviceId, 'link' => $link, 'quantity' => $quantity], true);
    }

    public function status(string $providerOrderId): SmmResponse
    {
        return $this->call(['action' => 'status', 'order' => $providerOrderId], false);
    }

    /**
     * @param array<string,scalar> $fields
     * @param bool $mutating true for calls that create something (affects how failures are classified)
     */
    private function call(array $fields, bool $mutating, int $maxBytes = 262_144): SmmResponse
    {
        $t0 = microtime(true);
        $ms = static fn(): int => (int) round((microtime(true) - $t0) * 1000);

        try {
            $url = NetGuard::assertSafeUrl($this->apiUrl, 'SMM API URL');
        } catch (\InvalidArgumentException $e) {
            return new SmmResponse(SmmResponse::UNREACHABLE, error: $e->getMessage(), durationMs: $ms());
        }
        if ($this->apiKey === '') {
            return new SmmResponse(SmmResponse::UNREACHABLE, error: 'No API key is configured for this provider.', durationMs: $ms());
        }

        $p = parse_url($url);
        $host = (string) $p['host'];
        $port = (int) ($p['port'] ?? (($p['scheme'] ?? 'https') === 'https' ? 443 : 80));

        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['key' => $this->apiKey] + $fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $mutating ? 30 : 20,
            CURLOPT_FOLLOWLOCATION => false,          // a redirect could bounce us to an internal address
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SocialMediaBooster-SMM/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        // Pin the connection to an address we have just verified is public (defeats DNS rebinding).
        if (!Env::bool('ALLOW_PRIVATE_API_HOSTS', false) && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            try {
                $ips = NetGuard::resolve($host);
                foreach ($ips as $ip) {
                    if (!NetGuard::isPublicIp($ip)) {   // the answer may differ from the one assertSafeUrl() just checked
                        throw new \InvalidArgumentException('The SMM API URL points to a private or internal address, which is not allowed.');
                    }
                }
            } catch (\InvalidArgumentException $e) {
                return new SmmResponse(SmmResponse::UNREACHABLE, error: $e->getMessage(), durationMs: $ms());
            }
            $opts[CURLOPT_RESOLVE] = ["{$host}:{$port}:" . $ips[0]];
        }
        // Cap how much we are willing to read.
        $buffer = '';
        $opts[CURLOPT_RETURNTRANSFER] = false;
        $opts[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (&$buffer, $maxBytes): int {
            $buffer .= $chunk;
            return strlen($buffer) > $maxBytes ? 0 : strlen($chunk);
        };
        curl_setopt_array($ch, $opts);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch); // frees the handle (curl_close() is a no-op since PHP 8.0 and deprecated in 8.5)

        if ($errno !== 0) {
            // Failures before a byte reached the provider are safe to retry; anything later is ambiguous.
            $neverSent = in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT, 60, 51, 58, 59, 64, 77], true);
            $kind = ($neverSent || !$mutating) ? SmmResponse::UNREACHABLE : SmmResponse::AMBIGUOUS;
            return new SmmResponse($kind, error: 'Network error (' . $errno . '): ' . self::scrub($error), durationMs: $ms());
        }

        $data = json_decode($buffer, true);
        if (!is_array($data)) {
            $kind = $mutating ? SmmResponse::AMBIGUOUS : SmmResponse::UNREACHABLE;
            return new SmmResponse($kind, httpStatus: $http, error: 'The provider returned a non-JSON response (HTTP ' . $http . ').', durationMs: $ms(), rawBody: mb_substr($buffer, 0, 500));
        }
        if (isset($data['error']) && is_scalar($data['error'])) {
            return new SmmResponse(SmmResponse::REJECTED, $data, $http, mb_substr((string) $data['error'], 0, 300), $ms(), mb_substr($buffer, 0, 1000));
        }
        if ($http >= 500) {
            return new SmmResponse($mutating ? SmmResponse::AMBIGUOUS : SmmResponse::UNREACHABLE, $data, $http, 'The provider reported a server error (HTTP ' . $http . ').', $ms(), mb_substr($buffer, 0, 1000));
        }
        return new SmmResponse(SmmResponse::OK, $data, $http, '', $ms(), mb_substr($buffer, 0, 1000));
    }

    /** Never let a key slip into an error message. */
    private function scrub(string $s): string
    {
        return $this->apiKey !== '' ? str_replace($this->apiKey, '***', $s) : $s;
    }
}
