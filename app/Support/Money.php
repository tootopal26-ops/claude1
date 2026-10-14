<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decimal-safe money handling. Every amount in this application is an INTEGER
 * number of paise; floats are never used for money. Parsing is done on the
 * decimal STRING so "1.08" can never become 107.99999999999999.
 */
final class Money
{
    /**
     * Parse a rupee amount ("100", "100.5", "100.50", 100.5) into paise.
     * More than two decimal places, negatives, exponents → InvalidArgumentException.
     */
    public static function parseRupees(string|int|float|null $value): int
    {
        if ($value === null) {
            throw new \InvalidArgumentException('Amount is required.');
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Amount is not a finite number.');
            }
            // JSON numbers arrive as floats; render with 6 decimals and trim so 1.1 → "1.1" (not 1.100000000000000088…)
            $value = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }
        $s = trim((string) $value);
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,2})0*)?$/', $s, $m)) {
            throw new \InvalidArgumentException('Amount must be a positive number with at most two decimal places.');
        }
        $paise = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        return $paise;
    }

    /** 1080 → "10.80" (machine format, what the payment gateway expects). */
    public static function plain(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $paise = abs($paise);
        return $sign . intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Display format with Indian digit grouping: 123456 paise → "₹1,234.56";
     * whole rupees drop the decimals unless $forceDecimals.
     */
    public static function inr(int $paise, bool $forceDecimals = false): string
    {
        $neg = $paise < 0;
        $abs = abs($paise);
        $rupees = intdiv($abs, 100);
        $frac = $abs % 100;
        $out = '₹' . self::groupIndian((string) $rupees);
        if ($frac !== 0 || $forceDecimals) {
            $out .= '.' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
        }
        return ($neg ? '-' : '') . $out;
    }

    public static function groupIndian(string $digits): string
    {
        $len = strlen($digits);
        if ($len <= 3) {
            return $digits;
        }
        $last3 = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        return $rest . ',' . $last3;
    }

    /** round-half-away-from-zero of ($a * $b) / $d using integer maths only. */
    public static function mulDiv(int $a, int $b, int $d): int
    {
        if ($d === 0) {
            return 0;
        }
        $num = $a * $b;
        $neg = ($num < 0) xor ($d < 0);
        $num = abs($num);
        $d = abs($d);
        $q = intdiv($num, $d);
        if (($num % $d) * 2 >= $d) {
            $q++;
        }
        return $neg ? -$q : $q;
    }

    /** Percentage in tenths of a percent (59.2 % → 592), half-up. 0 when the denominator is 0. */
    public static function pctTenths(int $num, int $den): int
    {
        return $den === 0 ? 0 : self::mulDiv($num, 1000, $den);
    }

    /** "59.2" style string from tenths. */
    public static function tenthsToString(int $tenths): string
    {
        $neg = $tenths < 0;
        $t = abs($tenths);
        return ($neg ? '-' : '') . intdiv($t, 10) . '.' . ($t % 10);
    }

    public static function pct(int $num, int $den): string
    {
        return self::tenthsToString(self::pctTenths($num, $den));
    }

    /**
     * Parse a provider decimal ("0.27819", 3) into millionths (micros). Extra
     * decimals beyond 6 are truncated. Null when it is not a plain non-negative number.
     */
    public static function parseDecimalMicros(string|int|float|null $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $s = is_float($value) ? rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') : trim((string) $value);
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,6})\d*)?$/', $s, $m)) {
            return null;
        }
        return (int) $m[1] * 1_000_000 + (int) str_pad($m[2] ?? '0', 6, '0');
    }

    /**
     * Provider charge → INR paise:  paise = chargeMicros × rateMicros / 1e10
     * (rate = INR per 1 provider-currency unit, in millionths). Null if the
     * multiplication could overflow — callers then keep their estimate.
     */
    public static function chargeToPaise(int $chargeMicros, int $rateMicros): ?int
    {
        if ($chargeMicros < 0 || $rateMicros < 0) {
            return null;
        }
        if ($chargeMicros !== 0 && $rateMicros > intdiv(PHP_INT_MAX, $chargeMicros)) {
            return null;
        }
        return self::mulDiv($chargeMicros, $rateMicros, 10_000_000_000);
    }
}
