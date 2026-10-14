<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Password hashing + policy.
 *
 * Hashing uses Argon2id when the host's PHP has it, otherwise bcrypt (cost 12)
 * with a SHA-256 pre-hash so long passphrases are not truncated at 72 bytes.
 * Both are salted and slow; plaintext passwords are never stored or logged.
 */
final class Password
{
    private const COMMON = [
        'password', 'password1', 'password123', 'passw0rd', 'qwerty', 'qwerty123', 'letmein', 'welcome', 'admin',
        'administrator', 'iloveyou', 'abc123', '123456', '12345678', '123456789', '1234567890', 'changeme',
        'socialmedia', 'socialmediabooster', 'booster', 'smmpanel', 'smmadmin',
    ];

    public static function hash(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $h = password_hash($plain, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        } else {
            $h = password_hash(self::prehash($plain), PASSWORD_BCRYPT, ['cost' => 12]);
        }
        if (!is_string($h)) {
            throw new \RuntimeException('Password hashing failed.');
        }
        return $h;
    }

    public static function verify(string $plain, string $hash): bool
    {
        if (str_starts_with($hash, '$2y$')) {
            return password_verify(self::prehash($plain), $hash);
        }
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return !str_starts_with($hash, '$argon2id$')
                || password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        }
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** A hash of a random value, used to keep login timing equal for unknown users. */
    public static function dummyHash(): string
    {
        static $h = null;
        return $h ??= self::hash(bin2hex(random_bytes(16)));
    }

    private static function prehash(string $plain): string
    {
        return base64_encode(hash('sha256', $plain, true));
    }

    /**
     * @param array{username?:string,email?:string} $context
     * @return list<string> human readable problems; empty = acceptable
     */
    public static function problems(string $plain, array $context = []): array
    {
        $p = [];
        if (strlen($plain) < 12) {
            $p[] = 'Use at least 12 characters.';
        }
        if (strlen($plain) > 128) {
            $p[] = 'Use at most 128 characters.';
        }
        if (!preg_match('/[a-z]/', $plain)) {
            $p[] = 'Add a lowercase letter.';
        }
        if (!preg_match('/[A-Z]/', $plain)) {
            $p[] = 'Add an uppercase letter.';
        }
        if (!preg_match('/\d/', $plain)) {
            $p[] = 'Add a number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $plain)) {
            $p[] = 'Add a symbol (for example ! @ # $ %).';
        }
        $lower = strtolower($plain);
        foreach (self::COMMON as $c) {
            if (str_contains($lower, $c)) {
                $p[] = 'Avoid common words or passwords.';
                break;
            }
        }
        if (preg_match('/(.)\1{3,}/', $plain)) {
            $p[] = 'Avoid long runs of the same character.';
        }
        foreach (['username', 'email'] as $field) {
            $v = strtolower((string) ($context[$field] ?? ''));
            if ($field === 'email' && str_contains($v, '@')) {
                $v = substr($v, 0, (int) strpos($v, '@'));
            }
            if (strlen($v) >= 4 && str_contains($lower, $v)) {
                $p[] = 'Do not include your ' . $field . ' in the password.';
            }
        }
        return array_values(array_unique($p));
    }
}
