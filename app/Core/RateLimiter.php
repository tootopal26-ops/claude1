<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Clock;

/**
 * MySQL-backed fixed-window rate limiter (works across PHP workers/processes on
 * shared hosting, no Redis/APCu needed). The increment is a single atomic
 * INSERT … ON DUPLICATE KEY UPDATE.
 */
final class RateLimiter
{
    /**
     * Register one hit and report whether the caller is still within the limit.
     *
     * @return array{allowed:bool,hits:int,remaining:int,retry_after:int}
     */
    public static function hit(string $bucket, string $identity, int $limit, int $windowSeconds): array
    {
        $now = Clock::now();
        $window = intdiv($now, $windowSeconds);
        $key = sha1($bucket . '|' . $identity . '|' . $window);
        $expires = ($window + 1) * $windowSeconds + 60;

        Db::exec(
            'INSERT INTO rate_limits (k, hits, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$key, $expires]
        );
        $hits = (int) Db::val('SELECT hits FROM rate_limits WHERE k = ?', [$key], 1);

        return [
            'allowed'     => $hits <= $limit,
            'hits'        => $hits,
            'remaining'   => max(0, $limit - $hits),
            'retry_after' => max(1, ($window + 1) * $windowSeconds - $now),
        ];
    }

    /** Count a hit and throw 429 when over the limit. */
    public static function enforce(string $bucket, string $identity, int $limit, int $windowSeconds, string $message = ''): void
    {
        $r = self::hit($bucket, $identity, $limit, $windowSeconds);
        if (!$r['allowed']) {
            throw new HttpException(429, $message, 'rate_limited', ['retry_after' => $r['retry_after']], ['Retry-After' => (string) $r['retry_after']]);
        }
    }

    /** Read-only check (does not consume a hit). */
    public static function peek(string $bucket, string $identity, int $limit, int $windowSeconds): bool
    {
        $window = intdiv(Clock::now(), $windowSeconds);
        $hits = (int) Db::val('SELECT hits FROM rate_limits WHERE k = ?', [sha1($bucket . '|' . $identity . '|' . $window)], 0);
        return $hits < $limit;
    }

    public static function purgeExpired(): int
    {
        return Db::exec('DELETE FROM rate_limits WHERE expires_at < ?', [Clock::now()]);
    }
}
