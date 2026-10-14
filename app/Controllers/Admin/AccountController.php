<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Db;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminAccount;
use App\Services\AdminAuth;
use App\Services\Audit;
use App\Services\Cron;
use App\Services\Settings;
use App\Support\Clock;
use App\Support\Migrator;
use App\Support\Tz;

/** Password, sessions, audit trail and system health. */
final class AccountController extends BaseController
{
    public function changePassword(Request $req, array $p): Response
    {
        $auth = $this->auth($req);
        $in = $req->json();
        $problems = AdminAuth::changePassword($req, $auth, (string) ($in['current'] ?? ''), (string) ($in['new'] ?? ''), (string) ($in['confirm'] ?? ''));
        if ($problems) {
            throw new HttpException(422, $problems[0], 'invalid', ['problems' => $problems]);
        }
        return $this->ok(['message' => 'Password changed. Other devices were signed out.', 'sessions' => AdminAccount::sessions($auth)]);
    }

    public function sessions(Request $req, array $p): Response
    {
        $auth = $this->auth($req);
        $row = Db::one('SELECT username, email, last_login_at, last_login_ip, password_changed_at FROM admins WHERE id = ?', [$auth['admin']['id']]);
        return $this->ok([
            'sessions' => AdminAccount::sessions($auth),
            'account' => [
                'username' => $row['username'], 'email' => $row['email'], 'last_login_at' => Clock::iso($row['last_login_at']),
                'last_login_ip' => $row['last_login_ip'], 'password_changed_at' => Clock::iso($row['password_changed_at']),
                'must_change_password' => $auth['admin']['must_change_password'],
            ],
        ]);
    }

    public function revokeSession(Request $req, array $p): Response
    {
        $auth = $this->auth($req);
        AdminAccount::revokeSession($this->id($p), $auth, $req);
        return $this->ok(['sessions' => AdminAccount::sessions($auth)]);
    }

    public function revokeOthers(Request $req, array $p): Response
    {
        $auth = $this->auth($req);
        $n = AdminAccount::revokeOthers($auth, $req);
        return $this->ok(['message' => $n === 1 ? '1 other session signed out.' : $n . ' other sessions signed out.', 'sessions' => AdminAccount::sessions($auth)]);
    }

    public function audit(Request $req, array $p): Response
    {
        return $this->ok(AdminAccount::audit([
            'group' => $req->queryString('group', 'all', 20), 'q' => $req->queryString('q', '', 80), 'page' => $req->queryInt('page', 1, 1, 100000),
        ]));
    }

    // ── system ──

    public function system(Request $req, array $p): Response
    {
        $dbVersion = (string) Db::val('SELECT VERSION()');
        $exts = [];
        foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl', 'gd', 'fileinfo', 'json', 'sodium'] as $e) {
            $exts[] = ['name' => $e, 'ok' => extension_loaded($e), 'required' => $e !== 'sodium'];
        }
        $paths = [];
        foreach (['storage/logs', 'storage/cache', 'public/uploads'] as $d) {
            $full = SMB_ROOT . '/' . $d;
            $paths[] = ['path' => $d, 'ok' => is_dir($full) && is_writable($full)];
        }
        $cronLast = (int) Settings::get('cron.last_run', 0);
        $queue = [
            'payments_open' => (int) Db::val("SELECT COUNT(*) FROM payments WHERE status IN ('creating','pending')"),
            'orders_submit_due' => (int) Db::val("SELECT COUNT(*) FROM orders WHERE status IN ('PAID','PROCESSING') AND mode = 'AUTO'"),
            'orders_in_flight' => (int) Db::val("SELECT COUNT(*) FROM orders WHERE status IN ('SUBMITTED','IN_PROGRESS')"),
            'webhook_errors_24h' => (int) Db::val("SELECT COUNT(*) FROM webhook_events WHERE status IN ('invalid','error') AND received_at > ?", [Clock::sql(Clock::now() - 86400)]),
        ];
        return $this->ok(['system' => [
            'php' => PHP_VERSION, 'php_ok' => PHP_VERSION_ID >= 80100, 'database' => $dbVersion, 'server' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI),
            'env' => (string) Env::get('APP_ENV', 'production'), 'debug' => Env::debug(), 'app_url' => (string) Env::get('APP_URL', ''), 'https' => $req->isSecure(),
            'timezone' => Tz::name(), 'extensions' => $exts, 'paths' => $paths,
            'cron' => [
                'last_run_at' => $cronLast > 0 ? gmdate('c', $cronLast) : null, 'age_seconds' => $cronLast > 0 ? Clock::now() - $cronLast : null,
                'http_enabled' => (string) Env::get('CRON_KEY', '') !== '',
                // cPanel maps /usr/local/bin/php to the PHP version selected for the account; a bare `php` in cron may be an older system PHP
                'command' => (@is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php') . ' ' . SMB_ROOT . '/bin/cron.php',
                'url' => (string) Env::get('CRON_KEY', '') !== '' ? absolute_url('/cron/run') . '?key=YOUR_CRON_KEY' : null,
            ],
            'queue' => $queue,
            'migrations' => ['pending' => Migrator::pending(), 'applied' => (int) Db::val('SELECT COUNT(*) FROM schema_migrations')],
        ]]);
    }

    /**
     * POST /admin/api/system/migrate — apply pending database updates (after uploading a newer release; same as `php bin/migrate.php`).
     * Idempotent and serialised: a second click, or a cron/CLI run at the same moment, applies nothing twice.
     */
    public function migrate(Request $req, array $p): Response
    {
        $admin = $this->admin($req);
        RateLimiter::enforce('admin-migrate', (string) $admin['id'], 6, 300, 'Database updates were started a moment ago. Please wait a little.');
        if (!Db::tryLock('migrate', 5)) {
            throw new HttpException(409, 'Another database update is running. Please try again in a moment.', 'in_progress');
        }
        try {
            $applied = Migrator::run();
        } finally {
            Db::unlock('migrate');
        }
        if ($applied) {
            Audit::log('system.migrated', 'system', null, ['applied' => $applied], $req, $admin);
        }
        return $this->ok([
            'applied' => $applied, 'pending' => Migrator::pending(),
            'message' => $applied ? 'Database updated: ' . implode(', ', $applied) . '.' : 'The database is already up to date.',
        ]);
    }

    /** POST /admin/api/system/cron — run the background tasks right now. */
    public function runCron(Request $req, array $p): Response
    {
        RateLimiter::enforce('admin-run-cron', (string) $this->admin($req)['id'], 6, 300, 'Background tasks were started a moment ago. Please wait a little.');
        $result = Cron::run();
        return $this->ok(['result' => $result, 'message' => isset($result['skipped']) ? 'Another run is already in progress.' : 'Background tasks finished in ' . $result['took_ms'] . ' ms.']);
    }
}
