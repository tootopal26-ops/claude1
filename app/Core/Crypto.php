<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cryptographic helpers.
 *  - encrypt()/decrypt(): AES-256-GCM for secrets stored in the database
 *    (gateway API keys, SMM API keys). The key is derived from APP_KEY.
 *  - token(): URL-safe random identifiers.
 *  - hmac(): keyed hash used for CSRF tokens etc.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function appKey(): string
    {
        $raw = (string) Env::get('APP_KEY', '');
        if (str_starts_with($raw, 'base64:')) {
            $bin = base64_decode(substr($raw, 7), true);
            $raw = $bin === false ? '' : $bin;
        }
        if (strlen($raw) < 32) {
            throw new \RuntimeException('APP_KEY is missing or too short. Run the installer, or generate one with: php -r "echo \'base64:\'.base64_encode(random_bytes(32));"');
        }
        return $raw;
    }

    /** Purpose-bound sub-key so one leaked derived value never reveals another. */
    public static function subKey(string $purpose): string
    {
        return hash_hmac('sha256', 'smb|' . $purpose, self::appKey(), true);
    }

    public static function encrypt(string $plain): string
    {
        $key = self::subKey('db-secrets');
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        if (!str_starts_with($payload, 'v1:')) {
            return null;
        }
        $bin = base64_decode(substr($payload, 3), true);
        if ($bin === false || strlen($bin) < 29) {
            return null;
        }
        $iv = substr($bin, 0, 12);
        $tag = substr($bin, 12, 16);
        $cipher = substr($bin, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::subKey('db-secrets'), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    /** @param array<string,mixed> $data */
    public static function encryptJson(array $data): string
    {
        return self::encrypt(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    public static function decryptJson(?string $payload): array
    {
        $plain = self::decrypt($payload);
        if ($plain === null) {
            return [];
        }
        $arr = json_decode($plain, true);
        return is_array($arr) ? $arr : [];
    }

    /** Random hex string of $bytes*2 chars. */
    public static function token(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function hmac(string $purpose, string $data): string
    {
        return self::base64url(hash_hmac('sha256', $data, self::subKey($purpose), true));
    }

    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /** Mask a secret for display: ••••abcd */
    public static function mask(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return '';
        }
        $tail = strlen($secret) > 8 ? substr($secret, -4) : '';
        return '••••••••' . $tail;
    }
}
