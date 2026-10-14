<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSRF protection.
 *  - Signed-in admins: token = HMAC(per-session secret). Sent as the
 *    X-CSRF-Token header (fetch) or the _csrf form field.
 *  - Pre-login forms (login): signed double-submit cookie. The cookie carries
 *    "random.signature"; the form carries "random". Both must agree and the
 *    signature must verify, so an attacker cannot plant their own pair.
 */
final class Csrf
{
    private const PRE_COOKIE = 'smb_csrf';

    public static function forSession(string $sessionSecret): string
    {
        return Crypto::hmac('csrf-session', $sessionSecret);
    }

    public static function verifySession(Request $req, string $sessionSecret): bool
    {
        $sent = $req->header('x-csrf-token') ?? (string) $req->input('_csrf', '');
        return $sent !== '' && hash_equals(self::forSession($sessionSecret), $sent);
    }

    /** @return array{0:string,1:string} [formToken, cookieValue] */
    public static function issuePre(): array
    {
        $rand = Crypto::token(16);
        return [$rand, $rand . '.' . Crypto::hmac('csrf-pre', $rand)];
    }

    public static function preCookieName(): string
    {
        return self::PRE_COOKIE;
    }

    public static function verifyPre(Request $req): bool
    {
        $cookie = $req->cookie(self::PRE_COOKIE);
        $sent = (string) $req->input('_csrf', '');
        if ($cookie === null || $sent === '' || !str_contains($cookie, '.')) {
            return false;
        }
        [$rand, $sig] = explode('.', $cookie, 2);
        return hash_equals($rand, $sent) && hash_equals(Crypto::hmac('csrf-pre', $rand), $sig);
    }

    /** Standard cookie flags used across the app. */
    public static function cookieOptions(Request $req, int $expires = 0, string $sameSite = 'Lax', bool $httpOnly = true): array
    {
        return [
            'expires'  => $expires,
            'path'     => base_path() === '' ? '/' : base_path() . '/',
            'secure'   => $req->isSecure(),
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ];
    }
}
