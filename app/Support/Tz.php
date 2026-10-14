<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Env;

/**
 * Business time-zone helpers. Instants are stored in UTC; "calendar days" for
 * reports are computed here, in PHP, when a row is written — so MySQL never
 * needs its time-zone tables (often missing on shared hosting).
 */
final class Tz
{
    private static ?string $name = null;

    public static function name(): string
    {
        if (self::$name === null) {
            $name = (string) Env::get('APP_TIMEZONE', 'Asia/Kolkata');
            try {
                $stored = \App\Services\Settings::get('business.timezone');
                if (is_string($stored) && $stored !== '') {
                    $name = $stored;
                }
            } catch (\Throwable) {
                // DB not ready (installer / CLI before migrate) — fall back to env.
            }
            self::$name = self::isValid($name) ? $name : 'Asia/Kolkata';
        }
        return self::$name;
    }

    public static function set(string $name): void
    {
        self::$name = self::isValid($name) ? $name : self::$name;
    }

    public static function reset(): void
    {
        self::$name = null;
    }

    public static function isValid(string $name): bool
    {
        return in_array($name, \DateTimeZone::listIdentifiers(), true);
    }

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::name());
    }

    /** "Y-m-d" of $ts in the business zone. */
    public static function dayOf(?int $ts = null): string
    {
        return (new \DateTimeImmutable('@' . ($ts ?? Clock::now())))->setTimezone(self::zone())->format('Y-m-d');
    }

    public static function today(): string
    {
        return self::dayOf(Clock::now());
    }

    /** Offset from UTC in seconds at $ts (for hour bucketing in SQL). */
    public static function offsetSeconds(?int $ts = null): int
    {
        return self::zone()->getOffset(new \DateTimeImmutable('@' . ($ts ?? Clock::now())));
    }

    /** UTC unix time of local midnight starting $day. */
    public static function dayStart(string $day): int
    {
        return (new \DateTimeImmutable($day . ' 00:00:00', self::zone()))->getTimestamp();
    }

    /** UTC SQL datetime of local midnight starting $day (inclusive lower bound). */
    public static function dayStartSql(string $day): string
    {
        return Clock::sql(self::dayStart($day));
    }

    public static function addDays(string $day, int $n): string
    {
        return (new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    public static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));
        return (int) $a->diff($b)->format('%r%a');
    }

    public static function isDay(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y);
    }

    /** Human label for the zone, e.g. "Asia/Kolkata (UTC+05:30)". */
    public static function label(): string
    {
        $off = self::offsetSeconds();
        $sign = $off < 0 ? '-' : '+';
        $off = abs($off);
        return self::name() . sprintf(' (UTC%s%02d:%02d)', $sign, intdiv($off, 3600), intdiv($off % 3600, 60));
    }
}
