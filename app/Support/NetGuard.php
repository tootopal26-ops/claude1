<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Env;

/**
 * SSRF protection for URLs an ADMIN types in (payment-gateway base URL, SMM API
 * URL). The server will call these, so they must not be able to reach internal
 * services (localhost, metadata endpoints, private networks).
 *
 * Production: https only, and every resolved address must be public.
 * Local development can opt out with ALLOW_PRIVATE_API_HOSTS=true (mock servers).
 */
final class NetGuard
{
    /**
     * @return string normalised URL without trailing slash
     * @throws \InvalidArgumentException message is safe to show to the admin
     */
    public static function assertSafeUrl(string $url, string $what = 'URL'): string
    {
        $url = trim($url);
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            throw new \InvalidArgumentException("The {$what} is not a valid URL (example: https://api.example.com).");
        }
        $scheme = strtolower($p['scheme']);
        $allowPrivate = Env::bool('ALLOW_PRIVATE_API_HOSTS', false);
        if (!in_array($scheme, ['https', 'http'], true) || ($scheme === 'http' && !$allowPrivate)) {
            throw new \InvalidArgumentException("The {$what} must start with https://.");
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new \InvalidArgumentException("The {$what} must not contain a username or password.");
        }
        $host = strtolower(rtrim($p['host'], '.'), );
        if (!$allowPrivate) {
            foreach (self::resolve($host) as $ip) {
                if (!self::isPublicIp($ip)) {
                    throw new \InvalidArgumentException("The {$what} points to a private or internal address, which is not allowed.");
                }
            }
        }
        $port = isset($p['port']) ? ':' . (int) $p['port'] : '';
        $path = rtrim((string) ($p['path'] ?? ''), '/');
        $query = isset($p['query']) ? '?' . $p['query'] : '';
        return $scheme . '://' . $host . $port . $path . $query;
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return [trim($host, '[]')];
        }
        if ($host === 'localhost' || !str_contains($host, '.')) {
            return ['127.0.0.1'];
        }
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            foreach (is_array($aaaa) ? $aaaa : [] as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        if (!$ips) {
            throw new \InvalidArgumentException('The host name could not be resolved. Check the spelling of the URL.');
        }
        return $ips;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            $l = strtolower($ip);
            return !($l === '::1' || $l === '::' || str_starts_with($l, 'fe80:') || str_starts_with($l, 'fc') || str_starts_with($l, 'fd')
                || str_starts_with($l, '::ffff:') || str_starts_with($l, '64:ff9b:'));
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        foreach ([['127.0.0.0', 8], ['169.254.0.0', 16], ['100.64.0.0', 10], ['0.0.0.0', 8], ['192.0.0.0', 24], ['198.18.0.0', 15]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if ((($long & $mask) === (ip2long($net) & $mask))) {
                return false;
            }
        }
        return true;
    }
}
