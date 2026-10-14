<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Support\Clock;

/**
 * Admin authentication with DB-backed sessions.
 *
 *  - Only a SHA-256 of the cookie token is stored, so a database leak cannot be
 *    replayed as a login.
 *  - Sessions have an idle timeout and an absolute lifetime; "remember me" just
 *    makes both longer.
 *  - Logins are throttled per IP and per account, and an account locks for 15
 *    minutes after 5 consecutive failures.
 *  - Unknown accounts take the same time as wrong passwords (dummy hash).
 */
final class AdminAuth
{
    private const MAX_FAILS = 5;
    private const LOCK_SECONDS = 900;

    public static function cookieName(Request $req): string
    {
        // The __Host- prefix makes the browser refuse the cookie unless it is Secure, Path=/ and host-only.
        return ($req->isSecure() && base_path() === '') ? '__Host-smb_admin' : 'smb_admin';
    }

    /**
     * @return array{ok:bool,error?:string,field?:string,token?:string,admin?:array<string,mixed>,retry_after?:int}
     */
    public static function attempt(Request $req, string $identifier, string $password, bool $remember): array
    {
        $identifier = trim(substr($identifier, 0, 190));
        $ip = $req->ip();

        $byIp = RateLimiter::hit('admin-login-ip', $ip, 20, 900);
        $byId = RateLimiter::hit('admin-login-id', strtolower($identifier), 8, 900);
        if (!$byIp['allowed'] || !$byId['allowed']) {
            return ['ok' => false, 'error' => 'Too many sign-in attempts. Please wait a few minutes and try again.', 'retry_after' => max($byIp['retry_after'], $byId['retry_after'])];
        }
        if ($identifier === '' || $password === '' || strlen($password) > 1024) {
            return ['ok' => false, 'error' => 'Enter your username or email and your password.'];
        }

        $admin = Db::one('SELECT * FROM admins WHERE (username = ? OR email = ?) LIMIT 1', [$identifier, $identifier]);
        $generic = ['ok' => false, 'error' => 'Incorrect username/email or password.'];

        if ($admin === null || !(int) $admin['is_active']) {
            Password::verify($password, Password::dummyHash());   // equalise timing
            Audit::log('admin.login_failed', 'admin', null, ['identifier' => substr($identifier, 0, 64), 'reason' => 'unknown'], $req, ['id' => 0, 'username' => 'anonymous', 'email' => '']);
            return $generic;
        }

        $now = Clock::now();
        $lockedUntil = Clock::fromSql($admin['locked_until']);
        if ($lockedUntil !== null && $lockedUntil > $now) {
            Password::verify($password, Password::dummyHash());
            Audit::log('admin.login_failed', 'admin', $admin['id'], ['reason' => 'locked'], $req, self::actor($admin));
            return ['ok' => false, 'error' => 'This account is temporarily locked after too many failed attempts. Try again in a few minutes.', 'retry_after' => $lockedUntil - $now];
        }

        if (!Password::verify($password, (string) $admin['password_hash'])) {
            $fails = (int) $admin['failed_logins'] + 1;
            $update = ['failed_logins' => $fails, 'updated_at' => Clock::sql()];
            if ($fails >= self::MAX_FAILS) {
                $update['locked_until'] = Clock::sql($now + self::LOCK_SECONDS);
                $update['failed_logins'] = 0;
            }
            Db::update('admins', $update, 'id = ?', [$admin['id']]);
            Audit::log('admin.login_failed', 'admin', $admin['id'], ['reason' => 'bad_password', 'fails' => $fails], $req, self::actor($admin));
            return $generic;
        }

        if (Password::needsRehash((string) $admin['password_hash'])) {
            Db::update('admins', ['password_hash' => Password::hash($password)], 'id = ?', [$admin['id']]);
        }

        $token = Crypto::token(32);
        $life = $remember ? 30 * 86400 : 12 * 3600;
        $idle = $remember ? 7 * 86400 : 2 * 3600;
        Db::tx(static function () use ($admin, $token, $req, $remember, $now, $life, $idle): void {
            Db::insert('sessions', [
                'admin_id'        => $admin['id'],
                'token_hash'      => hash('sha256', $token),
                'csrf_secret'     => Crypto::token(16),
                'ip'              => $req->ip(),
                'user_agent'      => $req->userAgent(),
                'remember'        => $remember ? 1 : 0,
                'created_at'      => Clock::sql($now),
                'last_seen_at'    => Clock::sql($now),
                'expires_at'      => Clock::sql($now + $life),
                'idle_expires_at' => Clock::sql($now + $idle),
            ]);
            Db::update('admins', [
                'failed_logins' => 0, 'locked_until' => null,
                'last_login_at' => Clock::sql($now), 'last_login_ip' => $req->ip(), 'updated_at' => Clock::sql($now),
            ], 'id = ?', [$admin['id']]);
        });
        Audit::log('admin.login', 'admin', $admin['id'], ['remember' => $remember], $req, self::actor($admin));

        return ['ok' => true, 'token' => $token, 'admin' => $admin, 'life' => $life];
    }

    /** Attach the session cookie to a response. */
    public static function setCookie(Response $res, Request $req, string $token, bool $remember, int $life): void
    {
        $res->cookie(self::cookieName($req), $token, Csrf::cookieOptions($req, $remember ? Clock::now() + $life : 0, 'Lax', true));
    }

    public static function clearCookie(Response $res, Request $req): void
    {
        $res->cookie(self::cookieName($req), '', Csrf::cookieOptions($req, Clock::now() - 86400, 'Lax', true));
    }

    /**
     * Resolve the signed-in admin for this request.
     *
     * @return array{admin:array<string,mixed>,session:array<string,mixed>,csrf:string}|null
     */
    public static function resolve(Request $req): ?array
    {
        $token = $req->cookie(self::cookieName($req));
        if ($token === null || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $now = Clock::now();
        $row = Db::one(
            'SELECT s.id AS sid, s.admin_id, s.csrf_secret, s.remember, s.last_seen_at, s.expires_at, s.idle_expires_at, s.revoked_at, '
            . 'a.id, a.username, a.email, a.role, a.is_active, a.must_change_password '
            . 'FROM sessions s JOIN admins a ON a.id = s.admin_id WHERE s.token_hash = ? LIMIT 1',
            [hash('sha256', $token)]
        );
        if ($row === null || $row['revoked_at'] !== null || !(int) $row['is_active']) {
            return null;
        }
        if (Clock::fromSql($row['expires_at']) <= $now || Clock::fromSql($row['idle_expires_at']) <= $now) {
            return null;
        }
        // slide the idle window (at most once a minute to keep writes cheap)
        if ($now - (int) Clock::fromSql($row['last_seen_at']) > 60) {
            $idle = (int) $row['remember'] ? 7 * 86400 : 2 * 3600;
            Db::update('sessions', ['last_seen_at' => Clock::sql($now), 'idle_expires_at' => Clock::sql(min($now + $idle, (int) Clock::fromSql($row['expires_at'])))], 'id = ?', [$row['sid']]);
        }
        return [
            'admin'   => [
                'id' => (int) $row['id'], 'username' => (string) $row['username'], 'email' => (string) $row['email'],
                'role' => (string) $row['role'], 'must_change_password' => (bool) $row['must_change_password'],
            ],
            'session' => ['id' => (int) $row['sid'], 'csrf_secret' => (string) $row['csrf_secret']],
            'csrf'    => Csrf::forSession((string) $row['csrf_secret']),
        ];
    }

    public static function logout(Request $req): void
    {
        $token = $req->cookie(self::cookieName($req));
        if ($token !== null && preg_match('/^[a-f0-9]{64}$/', $token)) {
            Db::exec('UPDATE sessions SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL', [Clock::sql(), hash('sha256', $token)]);
        }
    }

    /** Revoke every session of an admin except one (after a password change). */
    public static function revokeOthers(int $adminId, int $keepSessionId): int
    {
        return Db::exec('UPDATE sessions SET revoked_at = ? WHERE admin_id = ? AND id <> ? AND revoked_at IS NULL', [Clock::sql(), $adminId, $keepSessionId]);
    }

    /**
     * Change the signed-in admin's password.
     *
     * @return list<string> problems (empty on success)
     */
    public static function changePassword(Request $req, array $auth, string $current, string $new, string $confirm): array
    {
        $admin = Db::one('SELECT * FROM admins WHERE id = ?', [$auth['admin']['id']]);
        if ($admin === null) {
            throw new HttpException(401);
        }
        RateLimiter::enforce('admin-pw-change', (string) $admin['id'], 6, 900, 'Too many password change attempts. Please wait a few minutes.');

        $problems = [];
        if (!Password::verify($current, (string) $admin['password_hash'])) {
            $problems[] = 'Your current password is incorrect.';
        }
        if ($new !== $confirm) {
            $problems[] = 'The new passwords do not match.';
        }
        if ($current !== '' && hash_equals($current, $new)) {
            $problems[] = 'Choose a password different from the current one.';
        }
        $problems = array_merge($problems, Password::problems($new, ['username' => (string) $admin['username'], 'email' => (string) $admin['email']]));
        if ($problems) {
            return array_values(array_unique($problems));
        }

        Db::update('admins', [
            'password_hash' => Password::hash($new), 'must_change_password' => 0,
            'password_changed_at' => Clock::sql(), 'updated_at' => Clock::sql(), 'failed_logins' => 0, 'locked_until' => null,
        ], 'id = ?', [$admin['id']]);
        $revoked = self::revokeOthers((int) $admin['id'], (int) $auth['session']['id']);
        Audit::log('admin.password_changed', 'admin', $admin['id'], ['other_sessions_revoked' => $revoked], $req, $auth['admin']);
        return [];
    }

    /** @return array{id:int,username:string,email:string} */
    private static function actor(array $admin): array
    {
        return ['id' => (int) $admin['id'], 'username' => (string) $admin['username'], 'email' => (string) $admin['email']];
    }
}
