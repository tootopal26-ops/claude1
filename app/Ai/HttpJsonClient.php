<?php

declare(strict_types=1);

namespace App\Ai;

use App\Core\Env;
use App\Support\NetGuard;

/**
 * SSRF-guarded "POST a JSON body, get a JSON body back" used by every AI provider adapter — the same shape of client as
 * App\Smm\SmmClient and App\Payments\AlphaUpiApi, generalised because each AI vendor's URL and auth header differ but the
 * transport rules (public addresses only, pinned resolution, no redirects, bounded time and bytes, no secret in an error) do not.
 */
final class HttpJsonClient
{
    /**
     * @param array<string,string> $headers already includes auth — never logged or echoed back
     * @param array<string,mixed> $body
     * @return array{ok:bool,http_status:?int,data:?array<string,mixed>,error:string,duration_ms:int,raw:string}
     */
    public static function postJson(string $url, array $headers, array $body, int $connectTimeoutS, int $timeoutS, int $maxBytes, string $secretToScrub = ''): array
    {
        $t0 = microtime(true);
        $ms = static fn(): int => (int) round((microtime(true) - $t0) * 1000);
        $scrub = static fn(string $s): string => $secretToScrub !== '' ? str_replace($secretToScrub, '***', $s) : $s;

        try {
            $safe = NetGuard::assertSafeUrl($url, 'AI provider URL');
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'http_status' => null, 'data' => null, 'error' => $e->getMessage(), 'duration_ms' => $ms(), 'raw' => ''];
        }
        $p = parse_url($safe);
        $host = (string) $p['host'];
        $port = (int) ($p['port'] ?? (($p['scheme'] ?? 'https') === 'https' ? 443 : 80));

        $ch = curl_init($safe);
        $hdrs = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutS,
            CURLOPT_TIMEOUT        => $timeoutS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SocialMediaBooster-AI/1.0',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if (!Env::bool('ALLOW_PRIVATE_API_HOSTS', false) && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            try {
                $ips = NetGuard::resolve($host);
                foreach ($ips as $ip) {
                    if (!NetGuard::isPublicIp($ip)) {
                        throw new \InvalidArgumentException('The AI provider URL points to a private or internal address, which is not allowed.');
                    }
                }
            } catch (\InvalidArgumentException $e) {
                return ['ok' => false, 'http_status' => null, 'data' => null, 'error' => $e->getMessage(), 'duration_ms' => $ms(), 'raw' => ''];
            }
            $opts[CURLOPT_RESOLVE] = ["{$host}:{$port}:" . $ips[0]];
        }
        $buffer = '';
        $opts[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (&$buffer, $maxBytes): int {
            $buffer .= $chunk;
            return strlen($buffer) > $maxBytes ? 0 : strlen($chunk);
        };
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'http_status' => null, 'data' => null, 'error' => 'Network error (' . $errno . '): ' . $scrub($error), 'duration_ms' => $ms(), 'raw' => ''];
        }
        $data = json_decode($buffer, true);
        if ($http < 200 || $http >= 300) {
            $msg = is_array($data) ? (string) ($data['error']['message'] ?? $data['error'] ?? 'HTTP ' . $http) : 'HTTP ' . $http;
            return ['ok' => false, 'http_status' => $http, 'data' => is_array($data) ? $data : null, 'error' => $scrub(mb_substr($msg, 0, 300)), 'duration_ms' => $ms(), 'raw' => mb_substr($buffer, 0, 2000)];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'http_status' => $http, 'data' => null, 'error' => 'The provider returned a non-JSON response.', 'duration_ms' => $ms(), 'raw' => mb_substr($buffer, 0, 500)];
        }
        return ['ok' => true, 'http_status' => $http, 'data' => $data, 'error' => '', 'duration_ms' => $ms(), 'raw' => mb_substr($buffer, 0, 4000)];
    }
}
