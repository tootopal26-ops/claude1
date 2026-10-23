<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Where a tapped banner may take a player: one of the app's own screens, or a secure
 * web page. Anything else (other schemes, unknown app paths) is refused, so the app never
 * has to guess what to do with a link.
 */
class AppLink implements ValidationRule
{
    /** In-app destinations the player app knows how to open. */
    public const APP_PATHS = [
        '#^/leaderboard$#',
        '#^/wallet$#',
        '#^/profile$#',
        '#^/notifications$#',
        '#^/tournaments(\?status=(upcoming|ongoing|completed))?$#',
        '#^/game/[1-9][0-9]{0,9}$#',
        '#^/tournament/[1-9][0-9]{0,9}$#',
    ];

    public static function isAppPath(string $value): bool
    {
        foreach (self::APP_PATHS as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (str_starts_with($value, '/')) {
            if (! self::isAppPath($value)) {
                $fail('Use one of the app screens listed below, or a full https:// address.');
            }

            return;
        }

        $isHttpsUrl = filter_var($value, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($value, PHP_URL_HOST) !== '';

        if (! $isHttpsUrl) {
            $fail('Use one of the app screens listed below, or a full https:// address.');
        }
    }
}
