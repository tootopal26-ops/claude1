<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Colour maths for the accent-colour setting: the primary button text is white on
 * `color-mix(in oklab, accent 86%, black)`, so we check WCAG contrast against exactly
 * that colour and refuse accents that would make button labels hard to read.
 */
final class Color
{
    public static function isHex(string $s): bool
    {
        return (bool) preg_match('/^#[0-9a-f]{6}$/iD', $s);
    }

    /** @return array{0:float,1:float,2:float} sRGB 0..1 */
    public static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
    }

    private static function toLinear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function fromLinear(float $c): float
    {
        $c = max(0.0, min(1.0, $c));
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }

    /** Relative luminance (WCAG) of linear-light RGB. */
    private static function luminance(float $r, float $g, float $b): float
    {
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * The colour CSS `color-mix(in oklab, <hex> <keep>%, #000)` produces, as linear-light RGB.
     * Mixing with black in OKLab scales L, a and b equally, which is what we reproduce here.
     *
     * @return array{0:float,1:float,2:float}
     */
    public static function mixWithBlackLinear(string $hex, float $keep): array
    {
        [$r, $g, $b] = array_map([self::class, 'toLinear'], self::rgb($hex));
        // linear sRGB → LMS → OKLab (Björn Ottosson)
        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        $l_ = $l ** (1 / 3);
        $m_ = $m ** (1 / 3);
        $s_ = $s ** (1 / 3);
        $L = (0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_) * $keep;
        $A = (1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_) * $keep;
        $B = (0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_) * $keep;
        // OKLab → linear sRGB
        $l2 = ($L + 0.3963377774 * $A + 0.2158037573 * $B) ** 3;
        $m2 = ($L - 0.1055613458 * $A - 0.0638541728 * $B) ** 3;
        $s2 = ($L - 0.0894841775 * $A - 1.2914855480 * $B) ** 3;
        return [
            max(0.0, min(1.0, 4.0767416621 * $l2 - 3.3077115913 * $m2 + 0.2309699292 * $s2)),
            max(0.0, min(1.0, -1.2684380046 * $l2 + 2.6097574011 * $m2 - 0.3413193965 * $s2)),
            max(0.0, min(1.0, -0.0041960863 * $l2 - 0.7034186147 * $m2 + 1.7076147010 * $s2)),
        ];
    }

    /** WCAG contrast ratio of white text on the accent as the primary button renders it. */
    public static function buttonContrast(string $hex): float
    {
        [$r, $g, $b] = self::mixWithBlackLinear($hex, 0.86);
        $lum = self::luminance($r, $g, $b);
        return 1.05 / ($lum + 0.05);
    }
}
