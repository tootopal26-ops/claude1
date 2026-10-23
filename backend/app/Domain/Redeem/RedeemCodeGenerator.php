<?php

namespace App\Domain\Redeem;

/**
 * Five-character codes drawn with a cryptographically secure generator from
 * A–Z and 0–9 minus the look-alikes (I, O, 0, 1): 32 symbols, ~33.5 million
 * codes. Uniqueness is the database's job (a unique index); this class only
 * produces candidates and normalizes what players type.
 */
class RedeemCodeGenerator
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 5;

    public function generate(): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($position = 0; $position < self::LENGTH; $position++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return $code;
    }

    /**
     * What a player typed or pasted, as the code it must be: upper case, with
     * spaces and punctuation dropped. Null when that isn't five letters/digits.
     */
    public static function normalize(string $input): ?string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));

        return strlen($code) === self::LENGTH ? $code : null;
    }
}
