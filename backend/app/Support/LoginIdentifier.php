<?php

namespace App\Support;

/**
 * Classifies a sign-in identifier exactly as typed, with no database
 * lookup: an email (contains "@"), a mobile number (normalises to
 * exactly 10 digits), or else a username. The canonical value this
 * returns is what every consumer — the login resolver, the login rate
 * limiter, login-attempt logging, and the repeated-failed-login risk
 * alert — groups by, so a player who varies how they type the same
 * mobile number, or the letter case of their email or username, is
 * still treated as the same target throughout.
 */
class LoginIdentifier
{
    /**
     * @return array{type: 'email'|'mobile'|'username', value: string}
     */
    public static function classify(string $identifier): array
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return ['type' => 'email', 'value' => mb_strtolower($identifier)];
        }

        if (MobileNumber::isValid($identifier)) {
            return ['type' => 'mobile', 'value' => MobileNumber::normalize($identifier)];
        }

        return ['type' => 'username', 'value' => mb_strtolower($identifier)];
    }

    public static function canonicalize(string $identifier): string
    {
        return self::classify($identifier)['value'];
    }
}
