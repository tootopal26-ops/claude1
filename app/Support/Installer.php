<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Db;
use App\Core\Env;

/**
 * Backs both the web installer (/install) and bin/install.php.
 *
 * Takeover protection: the web installer will only run for someone who can
 * read storage/install-token.txt on the server (File Manager / FTP / SSH).
 * Without that, a stranger who reaches a fresh upload first could point the
 * site at THEIR database and become its owner.
 */
final class Installer
{
    public static function lockFile(): string
    {
        return SMB_ROOT . '/storage/installed.lock';
    }

    public static function tokenFile(): string
    {
        return SMB_ROOT . '/storage/install-token.txt';
    }

    public static function isInstalled(): bool
    {
        static $cached = null;
        if ($cached === true) {
            return true;
        }
        if (is_file(self::lockFile())) {
            return $cached = true;
        }
        // Installed by CLI / manual import without the lock file? Detect and heal.
        try {
            if (Env::get('DB_NAME') && (int) Db::val('SELECT COUNT(*) FROM admins') > 0) {
                @file_put_contents(self::lockFile(), gmdate('c') . "\n");
                return $cached = true;
            }
        } catch (\Throwable) {
            // not installed (no DB / no tables yet)
        }
        return false;
    }

    public static function token(): string
    {
        $f = self::tokenFile();
        if (!is_file($f)) {
            @file_put_contents($f, bin2hex(random_bytes(12)) . "\n");
            @chmod($f, 0600);
        }
        return trim((string) @file_get_contents($f));
    }

    /** @return list<array{label:string,ok:bool,required:bool,detail:string}> */
    public static function requirements(): array
    {
        $dirs = ['storage/logs', 'storage/cache', 'public/uploads'];
        $out = [
            ['label' => 'PHP 8.1 or newer', 'ok' => PHP_VERSION_ID >= 80100, 'required' => true, 'detail' => 'Found ' . PHP_VERSION],
        ];
        foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl', 'json', 'fileinfo', 'gd'] as $ext) {
            $out[] = ['label' => "PHP extension: {$ext}", 'ok' => extension_loaded($ext), 'required' => true, 'detail' => extension_loaded($ext) ? 'enabled' : 'missing — enable it in cPanel → Select PHP Version'];
        }
        $gdWebp = function_exists('imagewebp');
        $out[] = ['label' => 'GD WebP support (smaller logos)', 'ok' => $gdWebp, 'required' => false, 'detail' => $gdWebp ? 'available' : 'not available — PNG will be used instead'];
        foreach ($dirs as $d) {
            $w = is_dir(SMB_ROOT . '/' . $d) && is_writable(SMB_ROOT . '/' . $d);
            $out[] = ['label' => "Writable folder: {$d}", 'ok' => $w, 'required' => true, 'detail' => $w ? 'writable' : 'not writable — set permissions to 755 (or 775)'];
        }
        $envOk = is_writable(SMB_ROOT) || (is_file(SMB_ROOT . '/.env') && is_writable(SMB_ROOT . '/.env'));
        $out[] = ['label' => 'Can write the .env file', 'ok' => $envOk, 'required' => true, 'detail' => $envOk ? 'yes' : 'the project folder is not writable'];
        return $out;
    }

    public static function requirementsMet(): bool
    {
        foreach (self::requirements() as $r) {
            if ($r['required'] && !$r['ok']) {
                return false;
            }
        }
        return true;
    }

    /** @return string|null error message, or null if the connection works */
    public static function testDatabase(string $host, int $port, string $name, string $user, string $pass): ?string
    {
        return self::probeDatabase($host, $port, $name, $user, $pass)['error'];
    }

    /**
     * Connect, check the server version and make sure the user may create tables.
     *
     * @return array{ok:bool,error:?string,version:string}
     */
    public static function probeDatabase(string $host, int $port, string $name, string $user, string $pass): array
    {
        try {
            $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 6,
            ]);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $maria = stripos($version, 'mariadb') !== false;
            if (preg_match('/(\d+)\.(\d+)/', $version, $m)) {
                $ok = $maria ? ((int) $m[1] > 10 || ((int) $m[1] === 10 && (int) $m[2] >= 3)) : ((int) $m[1] > 5 || ((int) $m[1] === 5 && (int) $m[2] >= 7));
                if (!$ok) {
                    return ['ok' => false, 'version' => $version, 'error' => "This database server is too old ({$version}). MySQL 5.7+ or MariaDB 10.3+ is required — pick a newer version in cPanel."];
                }
            }
            try {
                $pdo->exec('CREATE TABLE `_smb_probe` (id INT) ENGINE=InnoDB');
                $pdo->exec('DROP TABLE `_smb_probe`');
            } catch (\PDOException) {
                return ['ok' => false, 'version' => $version, 'error' => 'The database user cannot create tables. In cPanel → MySQL® Databases, add the user to the database with ALL PRIVILEGES.'];
            }
            return ['ok' => true, 'version' => $version, 'error' => null];
        } catch (\PDOException $e) {
            // Show a friendly reason, never the password.
            $m = $e->getMessage();
            return ['ok' => false, 'version' => '', 'error' => match (true) {
                str_contains($m, 'Access denied')  => 'Access denied: check the database user and password (cPanel prefixes names, e.g. cpuser_dbname).',
                str_contains($m, 'Unknown database') => 'Unknown database: create it in cPanel → MySQL® Databases and add the user to it with ALL PRIVILEGES.',
                str_contains($m, 'getaddrinfo'), str_contains($m, 'Connection refused'), str_contains($m, 'No such file') => 'Could not reach the database server. On cPanel the host is normally "localhost".',
                default => 'Could not connect: ' . preg_replace('/(password|pwd)=\S+/i', '$1=***', $m),
            }];
        }
    }

    // ── web installer session (no database exists yet, so everything derives from the secret token file) ──

    public static function sessionCookie(): string
    {
        return 'smb_install';
    }

    public static function sessionValue(): string
    {
        return hash_hmac('sha256', 'smb-install-session', self::token());
    }

    public static function csrf(): string
    {
        return hash_hmac('sha256', 'smb-install-csrf', self::token());
    }

    /**
     * Throttle token guesses without a database: a tiny JSON file with hashed IPs.
     *
     * @return array{allowed:bool,retry_after:int}
     */
    public static function throttle(string $ip, bool $failed): array
    {
        $file = SMB_ROOT . '/storage/cache/install-attempts.json';
        $now = time();
        $data = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
        // forget old entries: a row is only kept for an hour after its last failure
        $data = array_filter($data, static fn($v) => is_array($v) && ($v['last'] ?? 0) > $now - 3600);
        $key = hash('sha256', $ip);
        $row = $data[$key] ?? ['fails' => 0, 'until' => 0, 'last' => 0];
        if ((int) $row['until'] > $now) {
            return ['allowed' => false, 'retry_after' => (int) $row['until'] - $now];
        }
        if ($failed) {
            // failures count inside a 15-minute window
            $row['fails'] = ($now - (int) $row['last'] > 900 ? 0 : (int) $row['fails']) + 1;
            $row['last'] = $now;
            if ($row['fails'] >= 8) {
                $row['until'] = $now + 900;
                $row['fails'] = 0;
            }
            $data[$key] = $row;
            @file_put_contents($file, json_encode($data), LOCK_EX);
        }
        return ['allowed' => true, 'retry_after' => 0];
    }

    /** The URL prefix the app is served from ("" at the domain root, "/shop" in a sub-folder). */
    public static function detectBasePath(string $requestUri): string
    {
        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '');
        $base = (string) preg_replace('#/install(/.*)?$#', '', $path);
        return rtrim($base, '/');
    }

    /** @return array<string,string> the KEY=value pairs of an existing .env (quotes removed). */
    private static function readEnvFile(): array
    {
        $existing = [];
        $file = SMB_ROOT . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
                if (preg_match('/^([A-Z_]+)=(.*)$/', trim($l), $m)) {
                    $existing[$m[1]] = trim($m[2], '"');
                }
            }
        }
        return $existing;
    }

    /**
     * The text of the .env file. Keeps an existing APP_KEY / CRON_KEY so re-running the
     * installer never invalidates encrypted settings.
     *
     * @param array<string,string> $v
     * @return array{text:string,key:string,cron:string}
     */
    public static function buildEnv(array $v): array
    {
        $existing = self::readEnvFile();
        $key = $existing['APP_KEY'] ?? '';
        if ($key === '') {
            $key = 'base64:' . base64_encode(random_bytes(32));
        }
        $cron = $existing['CRON_KEY'] ?? '';
        if ($cron === '') {
            $cron = bin2hex(random_bytes(20));
        }
        $q = static fn(string $s): string => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $s) . '"';
        $text = "# Generated by the installer on " . gmdate('c') . "\n"
            . "APP_ENV=" . ($v['APP_ENV'] ?? 'production') . "\n"
            . "APP_DEBUG=false\n"
            . "APP_URL=" . rtrim($v['APP_URL'], '/') . "\n"
            . "APP_KEY={$key}\n\n"
            . "DB_HOST=" . $q($v['DB_HOST']) . "\n"
            . "DB_PORT=" . (int) $v['DB_PORT'] . "\n"
            . "DB_NAME=" . $q($v['DB_NAME']) . "\n"
            . "DB_USER=" . $q($v['DB_USER']) . "\n"
            . "DB_PASS=" . $q($v['DB_PASS']) . "\n\n"
            . "APP_TIMEZONE=" . ($v['APP_TIMEZONE'] ?? 'Asia/Kolkata') . "\n"
            . "CRON_KEY={$cron}\n"
            . "TRUSTED_PROXY_HEADER=" . ($existing['TRUSTED_PROXY_HEADER'] ?? '') . "\n"
            . "ALLOW_PRIVATE_API_HOSTS=" . ($existing['ALLOW_PRIVATE_API_HOSTS'] ?? 'false') . "\n";
        return ['text' => $text, 'key' => $key, 'cron' => $cron];
    }

    /** @param array<string,string> $v */
    public static function writeEnv(array $v): void
    {
        $built = self::buildEnv($v);
        $file = SMB_ROOT . '/.env';
        if (@file_put_contents($file, $built['text'], LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the .env file. Check that the project folder is writable.');
        }
        @chmod($file, 0640);
        self::applyEnv($v, $built['key'], $built['cron']);
    }

    /** @param array<string,string> $v */
    private static function applyEnv(array $v, string $key, string $cron): void
    {
        foreach ($v as $k => $val) {
            Env::set($k, (string) $val);
        }
        Env::set('APP_KEY', $key);
        Env::set('CRON_KEY', $cron);
        Db::reset();
    }

    /**
     * Run migrations + seed + create the admin, then lock the installer.
     *
     * @return array{migrations:list<string>,admin_created:bool}
     */
    public static function finish(string $adminUsername, string $adminEmail, string $adminPassword, bool $mustChange): array
    {
        $applied = Migrator::run();
        Seeder::all();
        $created = Seeder::admin($adminUsername, $adminEmail, $adminPassword, $mustChange);
        @file_put_contents(self::lockFile(), gmdate('c') . "\n");
        @unlink(self::tokenFile());
        return ['migrations' => $applied, 'admin_created' => $created];
    }

    /**
     * Validate installer input.
     *
     * @param array<string,mixed> $in url, db_host, db_port, db_name, db_user, db_pass, site_name, timezone, admin_username, admin_email, admin_password
     * @return array<string,string> field => message (empty when valid)
     */
    public static function validate(array $in): array
    {
        $e = [];
        $s = static fn(string $k): string => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';
        $url = $s('url');
        $p = parse_url($url);
        if ($url === '' || !is_array($p) || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) {
            $e['url'] = 'Enter the full address of your site, for example https://example.com';
        }
        if (!preg_match('#^[A-Za-z0-9.\-_:/]{1,120}$#D', $s('db_host'))) {
            $e['db_host'] = 'Enter the database host (on cPanel this is normally "localhost").';
        }
        $port = $s('db_port') === '' ? '3306' : $s('db_port');          // note: "0" must NOT fall back to the default
        if (!preg_match('/^\d{1,5}$/D', $port) || (int) $port < 1 || (int) $port > 65535) {
            $e['db_port'] = 'Enter a port between 1 and 65535 (usually 3306).';
        }
        if (!preg_match('/^[A-Za-z0-9_$\-]{1,64}$/D', $s('db_name'))) {
            $e['db_name'] = 'Enter the database name (letters, numbers, _ and -; cPanel adds a prefix such as cpuser_).';
        }
        if ($s('db_user') === '' || strlen($s('db_user')) > 80) {
            $e['db_user'] = 'Enter the database user.';
        }
        if (strlen((string) ($in['db_pass'] ?? '')) > 200) {
            $e['db_pass'] = 'That password is too long.';
        }
        $name = $s('site_name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            $e['site_name'] = 'Enter your website name (2–60 characters).';
        }
        if (!Tz::isValid($s('timezone') ?: 'Asia/Kolkata')) {
            $e['timezone'] = 'Choose a valid time zone.';
        }
        $user = $s('admin_username');
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/D', $user)) {
            $e['admin_username'] = 'Use 3–64 letters, numbers, dots, dashes or underscores.';
        }
        $email = $s('admin_email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $e['admin_email'] = 'Enter a valid email address.';
        }
        $pw = (string) ($in['admin_password'] ?? '');
        $problems = \App\Services\Password::problems($pw, ['username' => $user, 'email' => $email]);
        if ($problems) {
            $e['admin_password'] = $problems[0] . (count($problems) > 1 ? ' (+' . (count($problems) - 1) . ' more)' : '');
        } elseif (isset($in['admin_confirm']) && (string) $in['admin_confirm'] !== $pw) {
            $e['admin_confirm'] = 'The two passwords do not match.';
        }
        return $e;
    }

    /**
     * The whole installation in one call (the web wizard and bin/install.php both use it).
     *
     * @param array<string,mixed> $in see validate()
     * @return array{admin_created:bool,migrations:list<string>,env_written:bool,url:string,cron_key:string,db_version:string}
     * @throws InstallException
     */
    public static function install(array $in): array
    {
        if (self::isInstalled()) {
            throw new InstallException('This site is already installed.', [], 'installed');
        }
        $fields = self::validate($in);
        if ($fields) {
            throw new InstallException('Please fix the highlighted fields.', $fields, 'invalid');
        }
        $s = static fn(string $k, string $d = ''): string => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : $d;
        $lock = @fopen(SMB_ROOT . '/storage/cache/install.running', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new InstallException('An installation is already running. Wait a moment and reload the page.', [], 'busy');
        }
        try {
            $dbPass = (string) ($in['db_pass'] ?? '');
            $probe = self::probeDatabase($s('db_host'), (int) ($s('db_port') === '' ? 3306 : $s('db_port')), $s('db_name'), $s('db_user'), $dbPass);
            if (!$probe['ok']) {
                throw new InstallException((string) $probe['error'], ['db_host' => (string) $probe['error']], 'database');
            }
            $env = [
                'APP_ENV' => 'production', 'APP_URL' => rtrim($s('url'), '/'), 'DB_HOST' => $s('db_host'), 'DB_PORT' => (string) ((int) ($s('db_port') === '' ? 3306 : $s('db_port'))),
                'DB_NAME' => $s('db_name'), 'DB_USER' => $s('db_user'), 'DB_PASS' => $dbPass, 'APP_TIMEZONE' => $s('timezone', 'Asia/Kolkata'),
            ];
            $built = self::buildEnv($env);
            $written = true;
            try {
                self::writeEnv($env);
            } catch (\RuntimeException) {
                // The project folder is read-only for PHP. Accept a hand-made .env that matches what was entered.
                $have = self::readEnvFile();
                $match = is_file(SMB_ROOT . '/.env') && ($have['DB_NAME'] ?? '') === $env['DB_NAME'] && ($have['DB_USER'] ?? '') === $env['DB_USER'] && ($have['APP_KEY'] ?? '') !== '';
                if (!$match) {
                    throw new InstallException('The installer cannot write the .env file. Create it by hand (see below), then press Install again.', [], 'env_not_writable', ['env' => $built['text']]);
                }
                $written = false;
                self::applyEnv($env, $have['APP_KEY'], $have['CRON_KEY'] ?? $built['cron']);
            }
            try {
                $r = self::finish($s('admin_username'), $s('admin_email'), (string) $in['admin_password'], !empty($in['must_change']));
                \App\Services\Settings::setMany([
                    'site.name' => $s('site_name'), 'business.timezone' => $s('timezone', 'Asia/Kolkata'),
                    'seo.title' => $s('site_name') . ' — Instagram & YouTube Growth Services',
                ]);
            } catch (\Throwable $e) {
                \App\Core\Log::exception($e, 'Installation failed');
                throw new InstallException('The database could not be prepared: ' . preg_replace('/(password|pwd)=\S+/i', '$1=***', $e->getMessage()), [], 'migrate');
            }
            return ['admin_created' => $r['admin_created'], 'migrations' => $r['migrations'], 'env_written' => $written, 'url' => rtrim($s('url'), '/'), 'cron_key' => (string) Env::get('CRON_KEY', ''), 'db_version' => $probe['version']];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function suggestUrl(?string $host, bool $https): string
    {
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) $host) ?: 'localhost';
        return ($https ? 'https://' : 'http://') . $host;
    }
}
