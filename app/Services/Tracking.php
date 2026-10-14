<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Tz;
use App\Support\Ua;

/**
 * Privacy-conscious visitor analytics.
 *
 *   visitor  = random id in a first-party cookie (smb_v, 400 days). No IP address,
 *              no fingerprint, no raw user-agent is stored.
 *   session  = one visit (cookie smb_s). A page refresh keeps the same session, so
 *              refreshing never creates a new visitor. A new session starts after
 *              30 minutes of inactivity.
 *   qualified= the visitor stayed on the site for the configured time (3 s). The
 *              browser reports it after that much VISIBLE time and the server
 *              double-checks the elapsed time, then charges one CPC for that
 *              visitor-day (see Ads).
 *   bots     = filtered by user-agent so crawlers never cost "ad money".
 */
final class Tracking
{
    public const VISITOR_COOKIE = 'smb_v';
    public const SESSION_COOKIE = 'smb_s';
    private const SESSION_IDLE  = 1800;

    /** @return array{visitor_id:?int,session_id:?int} read-only (used by checkout) */
    public static function context(Request $req): array
    {
        $c = self::lookup($req);
        return ['visitor_id' => $c['visitor'] ? (int) $c['visitor']['id'] : null, 'session_id' => $c['session'] ? (int) $c['session']['id'] : null];
    }

    /**
     * @return array{visitor:?array<string,mixed>,session:?array<string,mixed>}
     */
    private static function lookup(Request $req): array
    {
        $anon = $req->cookie(self::VISITOR_COOKIE);
        $skey = $req->cookie(self::SESSION_COOKIE);
        $visitor = null;
        $session = null;
        if ($anon !== null && preg_match('/^[a-f0-9]{32}$/', $anon)) {
            $visitor = Db::one('SELECT * FROM visitors WHERE anon_id = ?', [$anon]);
        }
        if ($visitor !== null && $skey !== null && preg_match('/^[a-f0-9]{32}$/', $skey)) {
            $session = Db::one(
                'SELECT * FROM visitor_sessions WHERE session_key = ? AND visitor_id = ? AND last_seen_at > ?',
                [$skey, $visitor['id'], Clock::sql(Clock::now() - self::SESSION_IDLE)]
            );
        }
        return ['visitor' => $visitor, 'session' => $session];
    }

    /**
     * A page load. Creates the visitor/session when needed.
     *
     * @param array<string,mixed> $p
     * @return array{body:array<string,mixed>,cookies:list<array{0:string,1:string,2:array<string,mixed>}>}
     */
    public static function hit(Request $req, array $p): array
    {
        $ua = $req->userAgent();
        if (Ua::isBot($ua)) {
            return ['body' => ['ok' => true, 'tracked' => false, 'reason' => 'bot'], 'cookies' => []];
        }
        RateLimiter::enforce('track-hit', $req->ip(), 120, 600);

        $now = Clock::now();
        $sqlNow = Clock::sql($now);
        $ctx = self::lookup($req);
        $cookies = [];
        $visitor = $ctx['visitor'];
        $session = $ctx['session'];
        $first = !empty($p['first']);

        $ownHost = parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: null;
        $refHost = Ua::host(isset($p['ref']) && is_string($p['ref']) ? $p['ref'] : null);
        $utm = static function (mixed $v): ?string {
            $v = is_string($v) ? strtolower(trim($v)) : '';
            $v = (string) preg_replace('/[^a-z0-9_.\- ]/', '', $v);
            return $v === '' ? null : substr($v, 0, 80);
        };
        $utmSource = $utm($p['utm_source'] ?? null);
        $source = Ua::source($utmSource, $refHost, is_string($ownHost) ? $ownHost : null);
        $device = Ua::device($ua);
        $path = self::cleanPath($p['path'] ?? '/');

        if ($visitor === null) {
            if (!$first) {
                // Cookies are blocked or were cleared mid-visit: do NOT mint a new visitor on every reload.
                return ['body' => ['ok' => true, 'tracked' => false, 'reason' => 'no_cookie'], 'cookies' => []];
            }
            RateLimiter::enforce('track-new-visitor', $req->ip(), 40, 3600);
            $anon = Crypto::token(16);
            $vid = Db::insert('visitors', [
                'anon_id' => $anon, 'first_seen_at' => $sqlNow, 'last_seen_at' => $sqlNow, 'first_landing_path' => $path,
                'first_referrer_host' => $refHost, 'first_source' => $source, 'device_type' => $device,
                'browser' => Ua::browser($ua), 'os' => Ua::os($ua), 'sessions_count' => 0,
            ]);
            $visitor = ['id' => $vid, 'anon_id' => $anon];
            $cookies[] = [self::VISITOR_COOKIE, $anon, Csrf::cookieOptions($req, $now + 400 * 86400)];
        }

        if ($session === null) {
            $skey = Crypto::token(16);
            $sid = Db::insert('visitor_sessions', [
                'visitor_id' => $visitor['id'], 'session_key' => $skey, 'day' => Tz::today(), 'started_at' => $sqlNow, 'last_hit_at' => $sqlNow,
                'last_seen_at' => $sqlNow, 'landing_path' => $path, 'referrer_host' => $refHost, 'source' => $source,
                'utm_source' => $utmSource, 'utm_medium' => $utm($p['utm_medium'] ?? null), 'utm_campaign' => $utm($p['utm_campaign'] ?? null),
                'device_type' => $device, 'browser' => Ua::browser($ua), 'os' => Ua::os($ua),
            ]);
            Db::exec('UPDATE visitors SET sessions_count = sessions_count + 1, last_seen_at = ? WHERE id = ?', [$sqlNow, $visitor['id']]);
            $session = ['id' => $sid, 'qualified_at' => null, 'source' => $source, 'device_type' => $device];
            $cookies[] = [self::SESSION_COOKIE, $skey, Csrf::cookieOptions($req, 0)];
        } else {
            Db::exec('UPDATE visitor_sessions SET last_hit_at = ?, last_seen_at = ? WHERE id = ?', [$sqlNow, $sqlNow, $session['id']]);
            Db::exec('UPDATE visitors SET last_seen_at = ? WHERE id = ?', [$sqlNow, $visitor['id']]);
        }

        // One row per visitor per business day — the unit that is billed.
        Db::exec(
            'INSERT IGNORE INTO visitor_days (visitor_id, day, source, device_type, created_at) VALUES (?, ?, ?, ?, ?)',
            [$visitor['id'], Tz::today(), $session['source'] ?? $source, $session['device_type'] ?? $device, $sqlNow]
        );

        $ads = Ads::current();
        return [
            'body' => ['ok' => true, 'tracked' => true, 'qualified' => $session['qualified_at'] !== null, 'qualify_ms' => $ads['qualify_seconds'] * 1000],
            'cookies' => $cookies,
        ];
    }

    /**
     * The browser reports 3+ seconds of visible time. Verify, then qualify the
     * session and charge ONE CPC for this visitor-day.
     *
     * @return array<string,mixed>
     */
    public static function qualify(Request $req): array
    {
        RateLimiter::enforce('track-qualify', $req->ip(), 60, 600);
        $ctx = self::lookup($req);
        if ($ctx['visitor'] === null || $ctx['session'] === null) {
            return ['ok' => true, 'qualified' => false, 'reason' => 'no_session'];
        }
        $s = $ctx['session'];
        if ($s['qualified_at'] !== null) {
            return ['ok' => true, 'qualified' => true, 'already' => true];
        }
        $ads = Ads::current();
        $since = Clock::now() - (int) Clock::fromSql($s['last_hit_at']);
        if ($since < $ads['qualify_seconds'] - 1) {   // 1 s tolerance for network jitter
            return ['ok' => true, 'qualified' => false, 'retry_ms' => max(500, ($ads['qualify_seconds'] - $since) * 1000)];
        }
        $now = Clock::sql();
        $day = Tz::today();
        $charged = Db::tx(static function () use ($s, $ctx, $ads, $now, $day): bool {
            Db::exec('UPDATE visitor_sessions SET qualified_at = ? WHERE id = ? AND qualified_at IS NULL', [$now, $s['id']]);
            Db::exec(
                'INSERT IGNORE INTO visitor_days (visitor_id, day, source, device_type, created_at) VALUES (?, ?, ?, ?, ?)',
                [$ctx['visitor']['id'], $day, $s['source'], $s['device_type'], $now]
            );
            // The first qualification of the day wins; later sessions the same day are free.
            return Db::exec(
                'UPDATE visitor_days SET qualified = 1, qualified_at = ?, cpc_paise = ? WHERE visitor_id = ? AND day = ? AND qualified = 0',
                [$now, $ads['cpc_paise'], $ctx['visitor']['id'], $day]
            ) === 1;
        });
        return ['ok' => true, 'qualified' => true, 'charged' => $charged];
    }

    /** Heartbeat: active seconds + which platform tab the visitor looked at. */
    public static function ping(Request $req, array $p): void
    {
        $ctx = self::lookup($req);
        if ($ctx['session'] === null) {
            return;
        }
        RateLimiter::enforce('track-ping', (string) $ctx['session']['id'], 30, 600);
        $secs = isset($p['active_s']) && is_numeric($p['active_s']) ? max(0, min(86400, (int) $p['active_s'])) : 0;
        $platform = isset($p['platform']) && is_string($p['platform']) && preg_match('/^[a-z0-9_-]{1,16}$/', $p['platform']) ? $p['platform'] : null;
        Db::exec(
            'UPDATE visitor_sessions SET active_seconds = GREATEST(active_seconds, ?), last_seen_at = ?, platform_viewed = COALESCE(?, platform_viewed) WHERE id = ?',
            [$secs, Clock::sql(), $platform, $ctx['session']['id']]
        );
    }

    // ── conversion flags (called by the order/payment code) ───────────────────

    public static function onOrderCreated(?int $visitorId, ?int $sessionId): void
    {
        self::flag($visitorId, $sessionId, 'order_created', 'has_order');
    }

    public static function onPaymentSucceeded(?int $visitorId, ?int $sessionId): void
    {
        self::flag($visitorId, $sessionId, 'payment_succeeded', 'has_paid');
    }

    private static function flag(?int $visitorId, ?int $sessionId, string $col, string $visitorCol): void
    {
        if ($visitorId === null) {
            return;
        }
        if ($sessionId !== null) {
            Db::exec("UPDATE visitor_sessions SET {$col} = 1 WHERE id = ?", [$sessionId]);
        }
        Db::exec("UPDATE visitors SET {$visitorCol} = 1 WHERE id = ?", [$visitorId]);
        $v = Db::one('SELECT first_source, device_type FROM visitors WHERE id = ?', [$visitorId]);
        Db::exec(
            "INSERT INTO visitor_days (visitor_id, day, source, device_type, {$col}, created_at) VALUES (?, ?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE {$col} = 1",
            [$visitorId, Tz::today(), $v['first_source'] ?? 'direct', $v['device_type'] ?? 'desktop', Clock::sql()]
        );
    }

    /** Remove raw session rows past the retention window (money-relevant aggregates live on). */
    public static function purgeOld(int $retentionDays = 400): int
    {
        return Db::exec('DELETE FROM visitor_sessions WHERE started_at < ?', [Clock::sql(Clock::now() - $retentionDays * 86400)]);
    }

    private static function cleanPath(mixed $p): string
    {
        $path = is_string($p) ? (string) parse_url($p, PHP_URL_PATH) : '/';
        $path = (string) preg_replace('/[^A-Za-z0-9\/_\-.~%]/', '', $path);
        return substr($path === '' || $path[0] !== '/' ? '/' . $path : $path, 0, 255);
    }
}
