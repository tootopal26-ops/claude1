<?php
/**
 * php bin/seed.php [--admin-username=NAME --admin-email=EMAIL --admin-password=SECRET]
 * Idempotent: inserts the default catalogue/settings if missing; creates the
 * first admin only when none exists yet.
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use App\Support\Seeder;

$opts = getopt('', ['admin-username::', 'admin-email::', 'admin-password::', 'must-change']);
echo json_encode(Seeder::all(), JSON_PRETTY_PRINT) . PHP_EOL;

if (isset($opts['admin-username'], $opts['admin-email'], $opts['admin-password'])) {
    $made = Seeder::admin((string) $opts['admin-username'], (string) $opts['admin-email'], (string) $opts['admin-password'], isset($opts['must-change']));
    echo $made ? "Admin account created.\n" : "An admin already exists — nothing changed.\n";
    @file_put_contents(\App\Support\Installer::lockFile(), gmdate('c') . "\n");
}
