<?php

namespace App\Domain\Notifications;

/**
 * Defense-in-depth: strips anything that looks like a secret, credential
 * or payment/room detail from a notification's `data` payload before it is
 * persisted or sent to a push provider. No current trigger puts such a
 * value in `data`, but this guards every future one (e.g. room credential
 * release in a later phase) without relying on every caller remembering to
 * scrub it themselves.
 */
class SanitizesNotificationData
{
    /**
     * @var string[]
     */
    private const array DENYLIST_SUBSTRINGS = [
        'password', 'secret', 'token', 'otp', 'credential', 'upi', 'api_key',
        'private_key', 'card_number', 'cvv', 'pin',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        return collect($data)
            ->reject(fn ($value, $key) => self::looksSensitive((string) $key))
            ->all();
    }

    private static function looksSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::DENYLIST_SUBSTRINGS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
