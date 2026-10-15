<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Idempotency;
use App\Core\RateLimiter;
use App\Support\Clock;
use App\Support\Tz;

/**
 * The work of each scheduled job (see Jobs::REGISTRY). Each handler works in small batches, looks at the JobContext's time budget between
 * them and returns a few counters for the run history. None of them is reachable from the web: they run from the CLI (bin/cron.php),
 * from the key-protected HTTP cron, or from the admin's "Run now" (permission jobs.run).
 */
final class JobHandlers
{
    /** @return array<string,mixed> */
    public static function payments(JobContext $c): array
    {
        $out = ['sweep' => $c->dryRun ? ['skipped' => 'dry run: no payment is expired, failed or settled'] : PaymentService::sweep()];
        if (!$c->expired()) {
            $out['risk'] = Risk::scan($c);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function recovery(JobContext $c): array
    {
        $out = ['submit' => Fulfilment::submitDue()];
        if (!$c->expired()) {
            $out['sync'] = Fulfilment::syncDue();
        }
        if (!$c->expired() && class_exists(Recovery::class)) {
            $out['recovery'] = Recovery::scan($c);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function analytics(JobContext $c): array
    {
        return ['days_refreshed' => Analytics::ensureFresh(Tz::addDays(Tz::today(), -3), Tz::addDays(Tz::today(), -1))];
    }

    /**
     * Retention: everything that has a lifetime is deleted here, and only here.
     *
     * @return array<string,int>
     */
    public static function cleanup(JobContext $c): array
    {
        $now = Clock::now();
        $n = [
            'rate_limits'          => RateLimiter::purgeExpired(),
            'idempotency'          => Idempotency::purgeExpired(),
            'admin_sessions'       => Db::exec('DELETE FROM sessions WHERE expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?)', [Clock::sql(), Clock::sql($now - 7 * 86400)]),
            'visitor_sessions'     => Tracking::purgeOld(400),
            'webhook_events'       => Db::exec('DELETE FROM webhook_events WHERE received_at < ?', [Clock::sql($now - 180 * 86400)]),
            'job_runs'             => Db::exec('DELETE FROM job_runs WHERE started_at < ?', [Clock::sql($now - 90 * 86400)]),
            'payment_flags'        => Db::exec('DELETE FROM payment_flags WHERE active_key IS NULL AND last_seen_at < ?', [Clock::sql($now - 365 * 86400)]),
            'ai_usage_logs'        => Db::exec('DELETE FROM ai_usage_logs WHERE created_at < ?', [Clock::sql($now - 180 * 86400)]),
            'support_conversations' => Db::exec('DELETE FROM support_conversations WHERE expires_at < ?', [Clock::sql()]),
            'pricing_assignments'  => Db::exec('DELETE FROM pricing_assignments WHERE expires_at < ?', [Clock::sql($now - 30 * 86400)]),
            'old_logs'             => 0,
        ];
        foreach (glob(SMB_ROOT . '/storage/logs/app-*.log') ?: [] as $f) {
            if (filemtime($f) < $now - 30 * 86400 && @unlink($f)) {
                $n['old_logs']++;
            }
        }
        return $n;
    }
}
