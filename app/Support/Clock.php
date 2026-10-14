<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of "now" (UTC unix seconds) so tests can freeze time.
 */
final class Clock
{
    private static ?int $fixed = null;

    public static function now(): int
    {
        return self::$fixed ?? time();
    }

    public static function setFixed(?int $ts): void
    {
        self::$fixed = $ts;
    }

    public static function advance(int $seconds): void
    {
        self::$fixed = self::now() + $seconds;
    }

    /** UTC "Y-m-d H:i:s" for DATETIME columns. */
    public static function sql(?int $ts = null): string
    {
        return gmdate('Y-m-d H:i:s', $ts ?? self::now());
    }

    /** DATETIME string (UTC) → unix seconds. */
    public static function fromSql(?string $s): ?int
    {
        if ($s === null || $s === '') {
            return null;
        }
        $t = strtotime($s . ' UTC');
        return $t === false ? null : $t;
    }

    public static function iso(?string $sql): ?string
    {
        $t = self::fromSql($sql);
        return $t === null ? null : gmdate('c', $t);
    }
}
