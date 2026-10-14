<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Idempotency;
use App\Core\Log;
use App\Core\RateLimiter;
use App\Support\Clock;
use App\Support\Tz;

/**
 * Background work, safe to call every minute from cPanel "Cron Jobs"
 * (php /home/USER/…/bin/cron.php), from an external pinger
 * (GET /cron/run?key=…), or — as a last resort for hosts without cron — from
 * visitor traffic (maybeRun). A MySQL advisory lock stops overlapping runs.
 */
final class Cron
{
    /** @return array<string,mixed> */
    public static function run(): array
    {
        if (!Db::tryLock('cron', 0)) {
            return ['skipped' => 'another run is in progress'];
        }
        $t0 = microtime(true);
        $out = [];
        try {
            foreach ([
                'payments'  => static fn() => PaymentService::sweep(),
                'submit'    => static fn() => Fulfilment::submitDue(),
                'sync'      => static fn() => Fulfilment::syncDue(),
                'analytics' => static fn() => ['days_refreshed' => Analytics::ensureFresh(Tz::addDays(Tz::today(), -3), Tz::addDays(Tz::today(), -1))],
                'purge'     => static fn() => self::purge(),
            ] as $name => $task) {
                try {
                    $out[$name] = $task();
                } catch (\Throwable $e) {
                    Log::exception($e, "Cron task '{$name}' failed");
                    $out[$name] = ['error' => $e->getMessage()];
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

    /** @return array<string,int> */
    private static function purge(): array
    {
        $now = Clock::now();
        $n = [
            'rate_limits'   => RateLimiter::purgeExpired(),
            'idempotency'   => Idempotency::purgeExpired(),
            'admin_sessions' => Db::exec('DELETE FROM sessions WHERE expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?)', [Clock::sql(), Clock::sql($now - 7 * 86400)]),
            'visitor_sessions' => Tracking::purgeOld(400),
            'webhook_events' => Db::exec('DELETE FROM webhook_events WHERE received_at < ?', [Clock::sql($now - 180 * 86400)]),
            'old_logs'      => 0,
        ];
        foreach (glob(SMB_ROOT . '/storage/logs/app-*.log') ?: [] as $f) {
            if (filemtime($f) < $now - 30 * 86400 && @unlink($f)) {
                $n['old_logs']++;
            }
        }
        return $n;
    }
}
