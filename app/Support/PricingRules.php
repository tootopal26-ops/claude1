<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The administrator's price-ending rule (master prompt, adaptive pricing §5), kept as pure functions so it can be tested without a
 * database: a candidate price is whole rupees, its last digit is 4 or 9, its leading digit is at least 2, and it is at or above the
 * configured minimum. ₹24, ₹29, ₹34, ₹39, ₹44, ₹49, ₹99 are valid; ₹4, ₹9, ₹14, ₹19 are not (leading digit below 2); ₹50 and ₹51 are
 * not (wrong last digit). This rule applies only to ADAPTIVE price candidates — an existing default price is never forced to match it.
 */
final class PricingRules
{
    public const DEFAULT_MIN_PAISE = 2000; // ₹20, the spec's stated starting minimum

    /** Exact rupees, no paise fraction (every price this rule accepts is a whole-rupee amount). */
    public static function isWholeRupees(int $paise): bool
    {
        return $paise > 0 && $paise % 100 === 0;
    }

    public static function validEnding(int $paise, int $minPaise = self::DEFAULT_MIN_PAISE): bool
    {
        if (!self::isWholeRupees($paise) || $paise < max(100, $minPaise)) {
            return false;
        }
        $rupees = intdiv($paise, 100);
        $last = $rupees % 10;
        if ($last !== 4 && $last !== 9) {
            return false;
        }
        // "leading digit at least 2" only means something once there IS a leading (tens-or-above) digit: ₹4 and ₹9 have none and
        // are rejected here, not by accident passing because their only digit happens to be >= 2.
        return strlen((string) $rupees) >= 2 && self::leadingDigit($rupees) >= 2;
    }

    public static function leadingDigit(int $rupees): int
    {
        return (int) substr((string) $rupees, 0, 1);
    }

    /** Why a candidate was rejected, in one sentence — for the admin form and the simulator. Null when it is valid. */
    public static function explainRejection(int $paise, int $minPaise = self::DEFAULT_MIN_PAISE): ?string
    {
        if (self::validEnding($paise, $minPaise)) {
            return null;
        }
        if (!self::isWholeRupees($paise)) {
            return 'Adaptive prices are whole rupees (no paise).';
        }
        if ($paise < max(100, $minPaise)) {
            return 'Below the configured minimum of ' . Money::inr(max(100, $minPaise), true) . '.';
        }
        $rupees = intdiv($paise, 100);
        if ($rupees % 10 !== 4 && $rupees % 10 !== 9) {
            return 'The price must end in 4 or 9 (for example ₹' . (intdiv($rupees, 10) * 10 + 9) . ').';
        }
        return 'The leading digit must be at least 2 (₹' . $rupees . ' is too small for the price-ending rule).';
    }

    /**
     * The nearest valid prices at or below / at or above $paise (for "did you mean ₹X?" suggestions). A bounded search — valid
     * endings repeat every 10 rupees, so at most ~12 steps are ever needed in either direction.
     *
     * @return array{below:?int,above:?int}
     */
    public static function suggestNear(int $paise, int $minPaise = self::DEFAULT_MIN_PAISE): array
    {
        $floor = max(1, intdiv(max(100, $minPaise), 100));
        $r = max($floor, intdiv($paise, 100));
        $below = null;
        for ($c = $r, $steps = 0; $c >= $floor && $steps < 20; $c--, $steps++) {
            if (self::validEnding($c * 100, $minPaise)) {
                $below = $c * 100;
                break;
            }
        }
        $above = null;
        for ($c = max($r, $floor), $steps = 0; $steps < 20; $c++, $steps++) {
            if (self::validEnding($c * 100, $minPaise)) {
                $above = $c * 100;
                break;
            }
        }
        return ['below' => $below, 'above' => $above];
    }
}
