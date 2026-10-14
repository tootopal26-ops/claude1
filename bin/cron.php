<?php
/**
 * cPanel → Cron Jobs → "Once Per Minute" (* * * * *):
 *   /usr/local/bin/php /home/USERNAME/path/to/bin/cron.php >/dev/null 2>&1
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

$out = App\Services\Cron::run();
if (in_array('-v', $argv, true) || in_array('--verbose', $argv, true)) {
    echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
}
