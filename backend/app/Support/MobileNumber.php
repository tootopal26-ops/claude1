<?php

namespace App\Support;

/**
 * The single source of truth for turning whatever a player typed or an
 * old app build sent into the plain 10-digit form every mobile number is
 * stored and compared as — never "+91", never a leading 0, never
 * punctuation. Every place that reads, validates, stores or searches a
 * mobile number goes through this instead of its own ad-hoc stripping.
 */
class MobileNumber
{
    /**
     * Strips everything but digits, then drops a "91" country code or a
     * leading trunk "0" ONLY when doing so leaves exactly 10 digits —
     * so a genuine 10-digit number that happens to start with "91" is
     * left alone rather than mistaken for "91" + an 8-digit number.
     */
    public static function normalize(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^\d{10}$/', self::normalize($value));
    }

    /**
     * For display only — never what gets stored or compared.
     */
    public static function format(string $value): string
    {
        return '+91 '.self::normalize($value);
    }
}
