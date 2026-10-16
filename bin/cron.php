<?php
/**
 * Scheduled work. cPanel → Cron Jobs.
 *
 * One line is enough (runs the quick jobs every time and every other job when it is due):
 *   * * * * *   /usr/local/bin/php /home/USERNAME/path/to/bin/cron.php >/dev/null 2>&1
 *
 * Or one line per job, each with its own lock, time budget and run history:
 *   php bin/cron.php payments            every 5 minutes    expire / re-check payments, payment-risk scan
 *   php bin/cron.php recovery            every 5 minutes    submit / retry / sync AUTO orders, recovery scan
 *   php bin/cron.php analytics           every 5 minutes    refresh dashboard rollups
 *   php bin/cron.php watchdog            every 15 minutes  the Business Watchdog's health checks (alerts)
 *   php bin/cron.php report              hourly            the daily report of every finished day that has none (safe to repeat)
 *   php bin/cron.php cleanup             hourly             retention
 *   (more jobs are listed by:  php bin/cron.php list)
 *
 *   php bin/cron.php list                the jobs, their suggested schedule and their last run
 *   php bin/cron.php <job> --dry-run     a dry run where the job supports one (pricing jobs)
 *   php bin/cron.php <job> -v            print the result
 *
 * The exit code is 0 when the run finished (or was skipped because the same job was already running) and 1 when it failed, so a cron
 * monitor can alert on it. Nothing here can be started from the web.
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

use App\Services\Cron;
use App\Services\Jobs;

$args = array_slice($argv, 1);
$verbose = in_array('-v', $args, true) || in_array('--verbose', $args, true);
$dry = in_array('--dry-run', $args, true);
$names = array_values(array_filter($args, static fn(string $a): bool => $a !== '' && $a[0] !== '-'));
$job = $names[0] ?? null;

if ($job === 'list') {
    foreach (Jobs::overview() as $j) {
        printf("%-18s %-9s %-14s last: %s\n", $j['job'], $j['state'], $j['cron'], $j['last_run'] ? ($j['last_run']['status'] . ' ' . $j['last_run']['at']) : 'never');
    }
    exit(0);
}

if ($job === null) {
    $out = Cron::run();
    if ($verbose) {
        echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
    }
    exit(0);
}

if (!Jobs::exists($job)) {
    fwrite(STDERR, "Unknown job '{$job}'. Run: php bin/cron.php list\n");
    exit(2);
}
$r = Jobs::run($job, ['dry_run' => $dry, 'source' => 'cron']);
if ($verbose || $r['status'] === 'failed') {
    echo json_encode($r, JSON_PRETTY_PRINT) . PHP_EOL;
}
exit($r['status'] === 'failed' ? 1 : 0);
