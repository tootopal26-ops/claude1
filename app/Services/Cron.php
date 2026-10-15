<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Support\Clock;

/**
 * Background work, safe to call every minute from cPanel "Cron Jobs"
 * (php /home/USER/…/bin/cron.php), from an external pinger
 * (GET /cron/run?key=…), or — as a last resort for hosts without cron — from
 * visitor traffic (maybeRun). A MySQL advisory lock stops overlapping runs.
 *
 * The work itself is a set of named jobs (App\Services\Jobs): this entry point runs the quick ones every time and every other job when it is
 * due, so one cron line is enough. Hosts that prefer one cron line per job call `php bin/cron.php <job>` instead (docs/OPERATIONS.md).
 */
final class Cron
{
    /** Jobs that run on every call (they are quick and are what keeps payments and orders moving). */
    private const EVERY_CALL = ['payments', 'recovery', 'analytics'];

    /** @return array<string,mixed> */
    public static function run(): array
    {
        if (!Db::tryLock('cron', 0)) {
            return ['skipped' => 'another run is in progress'];
        }
        $t0 = microtime(true);
        $out = [];
        try {
            foreach (self::EVERY_CALL as $job) {
                $out[$job] = self::brief(Jobs::run($job));
            }
            foreach (Jobs::runDue() as $job => $r) {
                if (!in_array($job, self::EVERY_CALL, true)) {
                    $out[$job] = self::brief($r);
                }
            }
            Settings::set('cron.last_run', Clock::now());
        } finally {
            Db::unlock('cron');
        }
        $out['took_ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $out;
    }

    /** Run only if the last run is older than $maxAge seconds (traffic-triggered fallback). */
    public static function maybeRun(int $maxAge = 120): ?array
    {
        $last = (int) Settings::get('cron.last_run', 0);
        return Clock::now() - $last >= $maxAge ? self::run() : null;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function brief(array $r): array
    {
        return $r['status'] === 'ok' || $r['status'] === 'timeout'
            ? ($r['result'] ?: ['status' => $r['status']]) + ($r['status'] === 'timeout' ? ['status' => 'timeout'] : [])
            : ['status' => $r['status'], 'error' => $r['error'] ?? ($r['result']['reason'] ?? null)];
    }
}
