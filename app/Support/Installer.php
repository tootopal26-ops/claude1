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
        try {
            $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 6,
            ]);
            $pdo->query('SELECT 1');
            return null;
        } catch (\PDOException $e) {
            // Show a friendly reason, never the password.
            $m = $e->getMessage();
            return match (true) {
                str_contains($m, 'Access denied')  => 'Access denied: check the database user and password (cPanel prefixes names, e.g. cpuser_dbname).',
                str_contains($m, 'Unknown database') => 'Unknown database: create it in cPanel → MySQL® Databases and add the user to it with ALL PRIVILEGES.',
                str_contains($m, 'getaddrinfo'), str_contains($m, 'Connection refused'), str_contains($m, 'No such file') => 'Could not reach the database server. On cPanel the host is normally "localhost".',
                default => 'Could not connect: ' . preg_replace('/(password|pwd)=\S+/i', '$1=***', $m),
            };
        }
    }

    /**
     * Write .env. Keeps an existing APP_KEY / CRON_KEY so re-running the
     * installer never invalidates encrypted settings.
     *
     * @param array<string,string> $v
     */
    public static function writeEnv(array $v): void
    {
        $existing = [];
        $file = SMB_ROOT . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
                if (preg_match('/^([A-Z_]+)=(.*)$/', trim($l), $m)) {
                    $existing[$m[1]] = $m[2];
                }
            }
        }
        $key = $existing['APP_KEY'] ?? '';
        if ($key === '') {
            $key = 'base64:' . base64_encode(random_bytes(32));
        }
        $cron = $existing['CRON_KEY'] ?? '';
        if ($cron === '') {
            $cron = bin2hex(random_bytes(20));
        }
        $q = static fn(string $s): string => '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $s) . '"';
        $env = "# Generated by the installer on " . gmdate('c') . "\n"
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
        if (@file_put_contents($file, $env, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the .env file. Check that the project folder is writable.');
        }
        @chmod($file, 0640);
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

    public static function suggestUrl(?string $host, bool $https): string
    {
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) $host) ?: 'localhost';
        return ($https ? 'https://' : 'http://') . $host;
    }
}
