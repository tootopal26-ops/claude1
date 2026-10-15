<?php

declare(strict_types=1);

namespace App\Payments;

use App\Core\Env;
use App\Support\Money;
use App\Support\NetGuard;
use App\Support\Upi;

/**
 * Reads the UPI payment request out of a gateway's HOSTED CHECKOUT PAGE.
 *
 * Why this exists. The documented Alpha UPI API (and its SDK) answers create-order with a hosted checkout URL and nothing else: no
 * UPI intent, no QR string, no QR image. The QR a customer scans is drawn by that checkout page. That page has to carry the UPI
 * request somewhere (it is what its QR encodes and what its "pay with your UPI app" buttons open), so — on the customer's behalf,
 * from the server — this class fetches the page, looks for `upi://pay?…` requests (also in the forms a page hides them in: HTML
 * entities, JSON / JS string escapes, URL-encoded inside an image address, an app's own scheme such as `phonepe://pay?…`) and hands
 * back the first one that is valid for EXACTLY this order's amount, in INR (App\Support\Upi). Nothing is invented: when the page
 * carries no such request the answer is "not found" and the caller shows the gateway's checkout instead of a QR.
 *
 * It is NOT an API of the gateway: it depends on what their page contains and can stop finding anything if they change it. The
 * admin screen has a "Check QR support" button that shows what the page contains for your account.
 *
 * Safety: only the gateway's own host (or a sub-domain) is fetched, over https (http only in local development), after the same SSRF
 * check as every admin-typed URL, pinned to the address that was checked, with a short timeout and a size cap; no credential is
 * ever sent (the page is public by its token), and nothing but a validated UPI request leaves this class.
 */
final class HostedCheckout
{
    private const MAX_BYTES = 600_000;
    private const TIMEOUT = 5;
    private const CONNECT_TIMEOUT = 3;

    /** The pattern of a UPI payment link in any of the usual wrappers; group 1 = its query string. */
    private const LINK = '#(?<![A-Za-z0-9])(?:(?:upi|phonepe|paytmmp|tez|gpay|bhim|credpay|mobikwik|amazonpay|whatsapp|freecharge|navi)(?::|%3A)(?://|%2F%2F)(?:upi(?:/|%2F))?pay'
        . '|intent(?::|%3A)(?://|%2F%2F)pay)(?:\?|%3F)([A-Za-z0-9\-._~:/?&=%@+,()!*]+)#i';

    /**
     * Fetch the checkout page of one order and read its UPI payment request.
     *
     * @return array{intent:?string,info:array<string,mixed>} `info` is safe to store and to show an admin (no credentials, no page content)
     */
    public static function discover(string $paymentUrl, string $gatewayBaseUrl, int $expectedPaise): array
    {
        $info = ['source' => 'checkout_page', 'found' => false, 'reason' => 'not_checked'];
        try {
            $target = self::target($paymentUrl, $gatewayBaseUrl);
        } catch (\InvalidArgumentException $e) {
            return ['intent' => null, 'info' => ['reason' => 'url_refused', 'detail' => mb_substr($e->getMessage(), 0, 160)] + $info];
        }

        $t0 = microtime(true);
        $page = self::get($target);
        $info['ms'] = (int) round((microtime(true) - $t0) * 1000);
        $info['http'] = $page['http'];
        $info['bytes'] = strlen($page['body']);
        $info['content_type'] = $page['type'];
        if ($page['error'] !== null) {
            return ['intent' => null, 'info' => ['reason' => 'fetch_failed', 'detail' => $page['error']] + $info];
        }
        if ($page['http'] < 200 || $page['http'] >= 300) {
            return ['intent' => null, 'info' => ['reason' => 'http_' . $page['http']] + $info];
        }
        if ($page['type'] !== '' && !preg_match('#^(text/|application/(xhtml\+xml|json|xml|javascript))#i', $page['type'])) {
            return ['intent' => null, 'info' => ['reason' => 'not_a_web_page'] + $info];
        }

        $read = self::read($page['body'], $expectedPaise);
        $info += [
            'candidates' => $read['candidates'], 'qr_hints' => $read['hints'],
        ];
        if ($read['intent'] === null) {
            return ['intent' => null, 'info' => ['reason' => $read['candidates'] === 0 ? 'no_upi_request_on_page' : 'upi_request_not_usable', 'why' => $read['why']] + $info];
        }
        $parsed = Upi::parse($read['intent']);
        return ['intent' => $read['intent'], 'info' => [
            'found' => true, 'reason' => 'found', 'payee' => $parsed['payee'] ?? null, 'amount_paise' => $parsed['amount_paise'] ?? null,
        ] + $info];
    }

    /**
     * Find the first UPI request in `$body` that is valid for exactly `$expectedPaise`.
     *
     * @return array{intent:?string,candidates:int,why:?string,hints:array<string,int>}
     */
    public static function read(string $body, int $expectedPaise): array
    {
        $why = null;
        $candidates = self::intents($body);
        foreach ($candidates as $uri) {
            $r = Upi::inspect($uri);
            if ($r['ok'] && $r['data']['amount_paise'] === $expectedPaise) {
                return ['intent' => $uri, 'candidates' => count($candidates), 'why' => null, 'hints' => self::hints($body)];
            }
            $why ??= $r['ok'] ? 'amount_is_' . Money::plain((int) $r['data']['amount_paise']) . '_not_' . Money::plain($expectedPaise) : $r['reason'];
        }
        return ['intent' => null, 'candidates' => count($candidates), 'why' => $why, 'hints' => self::hints($body)];
    }

    /**
     * Every UPI payment request the text contains, as `upi://pay?…`, best first (a plain upi:// link before an app's own scheme).
     *
     * @return list<string>
     */
    public static function intents(string $body): array
    {
        $variants = [$body];
        $decoded = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');          // &amp; → &
        if ($decoded !== $body) {
            $variants[] = $decoded;
        }
        $unescaped = str_ireplace(
            ['\\u0026', '\\u003d', '\\u002f', '\\u0025', '\\u003a', '\\u003f', '\\u0040', '\\u002b', '\\x26', '\\x3d', '\\x2f', '\\x3a', '\\x3f', '\\/'],
            ['&', '=', '/', '%', ':', '?', '@', '+', '&', '=', '/', ':', '?', '/'],
            $decoded
        );                                                                                  // JSON / JS string escapes
        if ($unescaped !== $decoded) {
            $variants[] = $unescaped;
        }

        $plain = [];
        $apps = [];
        foreach ($variants as $text) {
            if (preg_match_all(self::LINK, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach (array_slice($all, 0, 40) as $found) {
                $m = [$found[0][0], $found[1][0]];
                if (($text[$found[0][1] + strlen($found[0][0])] ?? '') === '\\') {
                    continue;                                                              // cut short by a string escape (\x26, \u0026): the unescaped copy carries the whole request
                }
                $query = $m[1];
                if (preg_match('/&(?:amp|quot|apos|lt|gt|#\d+|#x[0-9a-f]+)$/i', $query) || preg_match('/&(?:amp|quot|apos|lt|gt|#\d+|#x[0-9a-f]+)&/i', $query)) {
                    continue;                                                              // HTML-escaped text read raw (…&amp;…): the decoded copy carries the real one
                }
                if (!str_contains($query, '&') && preg_match('/%26|%3D/i', $query)) {
                    $query = rawurldecode($query);                                         // the whole request was URL-encoded (inside an image address)
                }
                foreach (array_unique([$query, rtrim($query, '.,;)')]) as $q) {
                    if ($q === '') {
                        continue;
                    }
                    $uri = 'upi://pay?' . $q;
                    if (stripos($m[0], 'upi://pay') === 0 || stripos($m[0], 'upi%3A') === 0) {
                        $plain[$uri] = true;
                    } else {
                        $apps[$uri] = true;
                    }
                }
            }
        }
        return array_slice(array_keys($plain + $apps), 0, 25);
    }

    /** Coarse facts about a page, for the "why not found" report: how many images / canvases / svgs and mentions of "qr". @return array<string,int> */
    private static function hints(string $body): array
    {
        return [
            'img' => min(99, substr_count(strtolower($body), '<img')), 'canvas' => min(99, substr_count(strtolower($body), '<canvas')),
            'svg' => min(99, substr_count(strtolower($body), '<svg')), 'qr' => min(999, substr_count(strtolower($body), 'qr')),
        ];
    }

    // ───────────────────────────── fetching ─────────────────────────────

    /**
     * @return array{url:string,host:string,port:int,ip:?string}
     * @throws \InvalidArgumentException
     */
    private static function target(string $paymentUrl, string $gatewayBaseUrl): array
    {
        $url = NetGuard::assertSafeUrl($paymentUrl, 'checkout page address');
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        $base = strtolower((string) parse_url($gatewayBaseUrl, PHP_URL_HOST));
        if ($host === '' || $base === '' || !($host === $base || str_ends_with($host, '.' . $base))) {
            throw new \InvalidArgumentException('The checkout page is not on the gateway\'s own host.');
        }
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ip = null;
        foreach (NetGuard::resolve($host) as $candidate) {
            if (!Env::bool('ALLOW_PRIVATE_API_HOSTS', false) && !NetGuard::isPublicIp($candidate)) {
                throw new \InvalidArgumentException('The checkout page points to a private address.');
            }
            if ($ip === null && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $candidate;                                                          // pinned below: the address that was checked is the one that is used
            }
        }
        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ip];
    }

    /** @return array{http:int,type:string,body:string,error:?string} */
    private static function get(array $target): array
    {
        $out = ['http' => 0, 'type' => '', 'body' => '', 'error' => null];
        if (!function_exists('curl_init')) {
            return ['error' => 'cURL is not available'] + $out;
        }
        $body = '';
        $type = '';
        $ch = curl_init($target['url']);
        $opts = [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: en-IN,en;q=0.8', 'Cache-Control: no-cache'],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SocialMediaBooster/1.0; checkout-qr)',
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$type): int {
                if (stripos($line, 'content-type:') === 0) {
                    $type = trim(substr($line, 13));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $body .= substr($chunk, 0, max(0, self::MAX_BYTES - strlen($body)));
                    return 0;                                                              // enough: stop the transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($target['ip'] !== null) {
            $opts[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $target['ip']];
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $out['http'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $out['body'] = $body;
        $out['type'] = $type;
        // the size cap aborts the transfer on purpose (CURLE_WRITE_ERROR): what was read so far is what we search
        if ($errno !== 0 && !($errno === 23 && strlen($body) >= self::MAX_BYTES)) {
            $out['error'] = 'Network error (' . $errno . '): ' . mb_substr($err, 0, 120);
        }
        return $out;
    }
}
