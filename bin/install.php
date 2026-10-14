<?php
/**
 * Command-line installer (alternative to the web installer, handy over SSH / cPanel Terminal).
 *
 *   php bin/install.php --url=https://example.com --db-name=cpuser_smm --db-user=cpuser_smm --db-pass='…' \
 *       --admin-username=owner --admin-email=you@example.com [--admin-password='…'] [--db-host=localhost] [--db-port=3306] \
 *       [--name="My Shop"] [--timezone=Asia/Kolkata] [--must-change-password] [--check]
 *
 * Missing values are asked for interactively (the admin password is read without echo).
 * --check only prints the server requirements and exits.
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use App\Support\InstallException;
use App\Support\Installer;

$o = getopt('', ['url:', 'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-pass:', 'name:', 'timezone:', 'admin-username:', 'admin-email:', 'admin-password:', 'must-change-password', 'check', 'help']);
if (isset($o['help'])) {
    echo trim((string) preg_replace('#^/\*\*|\s*\*/$#m', '', substr((string) file_get_contents(__FILE__), 6, 1100))) . PHP_EOL;
    exit(0);
}

echo "SOCIAL MEDIA BOOSTER — installer\n";
$bad = 0;
foreach (Installer::requirements() as $r) {
    $mark = $r['ok'] ? "\033[32m✓\033[0m" : ($r['required'] ? "\033[31m✗\033[0m" : "\033[33m!\033[0m");
    echo "  {$mark} {$r['label']} — {$r['detail']}\n";
    $bad += (!$r['ok'] && $r['required']) ? 1 : 0;
}
if (isset($o['check'])) {
    exit($bad ? 1 : 0);
}
if ($bad) {
    fwrite(STDERR, "\nFix the red items above first.\n");
    exit(1);
}
if (Installer::isInstalled()) {
    fwrite(STDERR, "\nThis site is already installed (storage/installed.lock exists). Nothing to do.\n");
    exit(1);
}

$tty = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
$ask = static function (string $label, ?string $default = null, bool $secret = false) use ($tty): string {
    if (!$tty) {
        return $default ?? '';
    }
    echo $label . ($default !== null && $default !== '' ? " [{$default}]" : '') . ': ';
    if ($secret) {
        @system('stty -echo');
    }
    $v = trim((string) fgets(STDIN));
    if ($secret) {
        @system('stty echo');
        echo "\n";
    }
    return $v === '' ? (string) $default : $v;
};
$opt = static fn(string $k, ?string $d = null): ?string => isset($o[$k]) && is_string($o[$k]) ? $o[$k] : $d;

$in = [
    'url'            => $opt('url') ?? $ask('Website address (https://example.com)'),
    'db_host'        => $opt('db-host') ?? $ask('Database host', 'localhost'),
    'db_port'        => $opt('db-port') ?? $ask('Database port', '3306'),
    'db_name'        => $opt('db-name') ?? $ask('Database name'),
    'db_user'        => $opt('db-user') ?? $ask('Database user'),
    'db_pass'        => $opt('db-pass') ?? $ask('Database password', null, true),
    'site_name'      => $opt('name') ?? $ask('Website name', 'SOCIAL MEDIA BOOSTER'),
    'timezone'       => $opt('timezone') ?? $ask('Business time zone', 'Asia/Kolkata'),
    'admin_username' => $opt('admin-username') ?? $ask('Admin username', 'owner'),
    'admin_email'    => $opt('admin-email') ?? $ask('Admin email'),
    'must_change'    => isset($o['must-change-password']),
];
$generated = false;
$pw = $opt('admin-password');
if ($pw === null) {
    $pw = $ask('Admin password (leave empty to generate a strong one)', null, true);
    if ($pw === '') {
        $pw = \App\Services\Password::generate();
        $generated = true;
    }
}
$in['admin_password'] = $pw;

echo "\nInstalling…\n";
try {
    $r = Installer::install($in);
} catch (InstallException $e) {
    fwrite(STDERR, "\n\033[31mInstallation failed:\033[0m " . $e->getMessage() . "\n");
    foreach ($e->fields as $f => $m) {
        fwrite(STDERR, "  - {$f}: {$m}\n");
    }
    if (isset($e->extra['env'])) {
        fwrite(STDERR, "\nCreate a file named .env with this content, then run the installer again:\n\n" . $e->extra['env'] . "\n");
    }
    exit(1);
}

echo "\n\033[32mDone.\033[0m Database {$r['db_version']} · migrations applied: " . count($r['migrations']) . ($r['admin_created'] ? ' · admin account created' : ' · an admin already existed (unchanged)') . "\n";
if ($generated) {
    echo "\nGenerated admin password (shown once — store it safely):\n  {$pw}\n";
}
echo "\nNext steps:\n"
    . "  1. Sign in:  {$r['url']}/admin/login\n"
    . "  2. Add this cron job (every minute):\n       * * * * * php " . SMB_ROOT . "/bin/cron.php >/dev/null 2>&1\n"
    . "  3. Admin → Payment gateway: add your Alpha UPI API key + webhook secret.\n";
if (!str_starts_with($r['url'], 'https://')) {
    echo "\n\033[33mNote:\033[0m the site address is not https:// — set up SSL and update APP_URL in .env before going live.\n";
}
