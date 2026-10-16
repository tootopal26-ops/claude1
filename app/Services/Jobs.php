<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Support\Clock;
use App\Support\Tz;

/**
 * Scheduled jobs, safe for plain cPanel Cron Jobs (PHP CLI, no always-on worker).
 *
 * Every run of every job:
 *   - takes a per-job MySQL advisory lock, so two cron processes (or a cron line and "Run now") can never work on the same job at once;
 *   - has a bounded time budget (a JobContext it checks between batches; the PHP time limit is raised only to that budget plus a margin);
 *   - is recorded in job_runs: start, finish, duration, a small result, and — if it failed — an error CATEGORY and a sanitised message
 *     (never a stack trace, a path or a secret). A run that died with its process is marked `abandoned` by the next run;
 *   - ends cleanly: an exception becomes a `failed` row, never an uncaught error that would leave the lock or the row behind.
 *
 * `runDue()` lets one every-minute cron line run each job at its own interval, so hosts with a single cron line get the whole schedule; hosts
 * that prefer one line per job use `php bin/cron.php <job>` (docs/OPERATIONS.md).
 */
final class Jobs
{
    /**
     * name => label, what it does, expected interval (s), time budget (s), suggested cPanel schedule.
     *
     * `daily_at` (HH:MM in the business time zone) makes `runDue()` start the job once a day after that time instead of every `every` seconds, and
     * `grace` is how long after its last success the job counts as overdue (default: three intervals and a minute).
     *
     * @var array<string,array{label:string,what:string,every:int,max:int,cron:string,daily_at?:string,grace?:int}>
     */
    public const REGISTRY = [
        'payments'         => ['label' => 'Payment reconciliation', 'what' => 'Expires unpaid sessions, re-checks pending and recently expired payments with the gateway, runs the payment-risk scan.', 'every' => 300, 'max' => 240, 'cron' => '*/5 * * * *'],
        'recovery'         => ['label' => 'Order recovery', 'what' => 'Submits paid AUTO orders that are waiting, retries safe failures with back-off, syncs live provider orders, hands stuck orders to a human.', 'every' => 300, 'max' => 240, 'cron' => '*/5 * * * *'],
        'analytics'        => ['label' => 'Analytics refresh', 'what' => 'Re-computes the dashboard rollups of recent days.', 'every' => 300, 'max' => 120, 'cron' => '*/5 * * * *'],
        'watchdog'         => ['label' => 'Business watchdog', 'what' => 'Runs the health checks (payment failures, notifications, provider calls, stuck orders, provider cost, missing costs, sales and conversion changes, failed jobs, application errors, money discrepancies) and raises, updates or resolves alerts.', 'every' => 900, 'max' => 120, 'cron' => '*/15 * * * *'],
        'report'           => ['label' => 'Daily report', 'what' => 'Writes the report of every finished business day (Asia/Kolkata) that has none yet. Safe to run as often as you like: a day that already has a report is left alone.', 'every' => 86400, 'max' => 180, 'cron' => '10 * * * *', 'daily_at' => '00:10', 'grace' => 93600],
        'cleanup'          => ['label' => 'Cleanup and retention', 'what' => 'Deletes expired rows (rate limits, idempotency keys, old sessions, support conversations past retention, old job history) and old logs.', 'every' => 3600, 'max' => 240, 'cron' => '17 * * * *'],
    ];

    /** Jobs for which "dry run" means something: they compute and report what they would do, and change nothing. The others have no dry-run mode. */
    public const DRY_RUN = ['payments', 'recovery', 'watchdog', 'report'];

    /** @var array<string,callable(JobContext):array<string,mixed>> */
    private static array $handlers = [];

    /** Register (or replace) a job's handler — used at startup by the services and by tests. */
    public static function register(string $job, callable $handler): void
    {
        self::$handlers[$job] = $handler;
    }

    /** Remove a handler registered with register() (tests restore the built-in one this way). */
    public static function unregister(string $job): void
    {
        unset(self::$handlers[$job]);
    }

    public static function supportsDryRun(string $job): bool
    {
        return in_array($job, self::DRY_RUN, true) || isset(self::$handlers[$job]);
    }

    public static function exists(string $job): bool
    {
        return isset(self::REGISTRY[$job]) || isset(self::$handlers[$job]);
    }

    /** @return callable(JobContext):array<string,mixed> */
    private static function handler(string $job): callable
    {
        if (isset(self::$handlers[$job])) {
            return self::$handlers[$job];
        }
        $map = [
            'payments'         => [JobHandlers::class, 'payments'],
            'recovery'         => [JobHandlers::class, 'recovery'],
            'analytics'        => [JobHandlers::class, 'analytics'],
            'watchdog'         => [JobHandlers::class, 'watchdog'],
            'report'           => [JobHandlers::class, 'report'],
            'cleanup'          => [JobHandlers::class, 'cleanup'],
        ];
        return isset($map[$job]) ? static fn(JobContext $c): array => $map[$job]($c) : throw new \InvalidArgumentException("Unknown job '{$job}'.");
    }

    /**
     * Run one job now.
     *
     * @param array{dry_run?:bool,source?:string,max_seconds?:int} $opts
     * @return array{job:string,status:string,duration_ms:int,result:array<string,mixed>,error_category:?string,error:?string,run_id:?int}
     */
    public static function run(string $job, array $opts = []): array
    {
        if (!self::exists($job)) {
            throw new \InvalidArgumentException("Unknown job '{$job}'.");
        }
        $dry = (bool) ($opts['dry_run'] ?? false);
        $source = in_array($opts['source'] ?? 'cron', ['cron', 'admin', 'web'], true) ? (string) ($opts['source'] ?? 'cron') : 'cron';
        $max = max(1, (int) ($opts['max_seconds'] ?? (self::REGISTRY[$job]['max'] ?? 120)));
        $out = ['job' => $job, 'status' => 'skipped', 'duration_ms' => 0, 'result' => ['reason' => 'another run of this job is in progress'], 'error_category' => null, 'error' => null, 'run_id' => null];

        if (!Db::tryLock('job:' . $job, 0)) {
            return $out;
        }
        $t0 = microtime(true);
        $runId = null;
        try {
            self::abandonStale($job, $max);
            $runId = Db::insert('job_runs', [
                'job' => $job, 'trigger_source' => $source, 'dry_run' => $dry ? 1 : 0, 'status' => 'running', 'started_at' => Clock::sql(),
            ]);
            $out['run_id'] = $runId;
            if (function_exists('set_time_limit')) {
                @set_time_limit($max + 30);
            }
            $ctx = new JobContext($t0 + $max, $dry);
            $result = (self::handler($job))($ctx);
            $result = is_array($result) ? $result : [];
            $result += $ctx->summary();
            $status = $ctx->stoppedEarly() ? 'timeout' : 'ok';
            $ms = (int) round((microtime(true) - $t0) * 1000);
            self::finish($runId, $status, $ms, $result, $status === 'timeout' ? 'timeout' : null, $status === 'timeout' ? 'The time budget of ' . $max . ' s ran out; the rest is picked up by the next run.' : null);
            return ['status' => $status, 'duration_ms' => $ms, 'result' => $result] + $out;
        } catch (\Throwable $e) {
            [$category, $message] = self::classify($e);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            try {
                Log::exception($e, "Scheduled job '{$job}' failed", ['category' => $category]);
                if ($runId !== null) {
                    self::finish($runId, 'failed', $ms, [], $category, $message);
                }
            } catch (\Throwable) {
                // the database may be what failed: nothing more can be recorded, and the job must still end cleanly
            }
            return ['status' => 'failed', 'duration_ms' => $ms, 'result' => [], 'error_category' => $category, 'error' => $message] + $out;
        } finally {
            Db::unlock('job:' . $job);
        }
    }

    /**
     * Run every job whose last start is older than its expected interval (or that never ran). For the one-cron-line setup.
     *
     * @param list<string>|null $only
     * @return array<string,array<string,mixed>>
     */
    public static function runDue(?array $only = null, string $source = 'cron'): array
    {
        $out = [];
        $now = Clock::now();
        foreach (self::REGISTRY as $job => $meta) {
            if ($only !== null && !in_array($job, $only, true)) {
                continue;
            }
            if (!self::isDue($job, $meta, $now)) {
                continue;
            }
            $out[$job] = self::run($job, ['source' => $source]);
        }
        return $out;
    }

    /**
     * Is the job due? An interval job when its last start is older than its interval; a daily job once per business day after its `daily_at` time —
     * and, if that run failed, again no sooner than 15 minutes later (so a persistent failure is not retried every minute).
     *
     * @param array<string,mixed> $meta
     */
    private static function isDue(string $job, array $meta, int $now): bool
    {
        if (isset($meta['daily_at'])) {
            [$h, $m] = array_map('intval', explode(':', (string) $meta['daily_at']));
            $due = Tz::dayStart(Tz::dayOf($now)) + $h * 3600 + $m * 60;
            if ($now < $due) {
                return false;
            }
            $done = Db::val("SELECT MAX(started_at) FROM job_runs WHERE job = ? AND dry_run = 0 AND status IN ('ok', 'timeout', 'running')", [$job]);
            if ($done !== null && (int) Clock::fromSql((string) $done) >= $due) {
                return false;
            }
            $last = Db::val('SELECT MAX(started_at) FROM job_runs WHERE job = ? AND dry_run = 0', [$job]);
            return $last === null || $now - (int) Clock::fromSql((string) $last) >= 900;
        }
        $last = Db::val('SELECT MAX(started_at) FROM job_runs WHERE job = ? AND dry_run = 0', [$job]);
        return $last === null || $now - (int) Clock::fromSql((string) $last) >= $meta['every'] - 20;
    }

    /** A run whose process was killed leaves a `running` row behind: close it so the history is truthful. */
    private static function abandonStale(string $job, int $max): void
    {
        Db::exec(
            "UPDATE job_runs SET status = 'abandoned', finished_at = ?, error_category = 'timeout', error_message = 'The run never reported back (the process was probably stopped).' "
            . "WHERE job = ? AND status = 'running' AND started_at < ?",
            [Clock::sql(), $job, Clock::sql(Clock::now() - ($max + 120))]
        );
    }

    /** @param array<string,mixed> $result */
    private static function finish(int $runId, string $status, int $ms, array $result, ?string $category, ?string $message): void
    {
        Db::update('job_runs', [
            'status' => $status, 'finished_at' => Clock::sql(), 'duration_ms' => $ms,
            'result_json' => $result ? mb_substr((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 4000) : null,
            'error_category' => $category, 'error_message' => $message !== null ? mb_substr($message, 0, 300) : null,
        ], 'id = ?', [$runId]);
    }

    /**
     * An error category and a message that is safe to store and show: no file paths, no SQL, no long tokens.
     *
     * @return array{0:string,1:string}
     */
    public static function classify(\Throwable $e): array
    {
        $msg = $e->getMessage();
        $category = match (true) {
            $e instanceof \PDOException => 'database',
            $e instanceof HttpException => 'logic',
            (bool) preg_match('/cURL|timed? ?out|could not resolve|connection (refused|reset)|network/i', $msg) => 'network',
            default => 'logic',
        };
        $safe = $category === 'database' ? 'A database error stopped the run (details are in the server log).' : self::clean($msg);
        return [$category, $safe];
    }

    /** Remove what must not be stored or shown: paths, long opaque strings (keys, tokens), control characters. Cut at $max characters (an error message: 300; an alert description: 1,000). */
    public static function clean(string $text, int $max = 300): string
    {
        $text = str_replace(SMB_ROOT . '/', '', $text);
        $text = (string) preg_replace('#(?:/[A-Za-z0-9_.\-]+){3,}#', '…', $text);
        $text = (string) preg_replace('/[A-Za-z0-9_\-]{28,}/', '…', $text);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, $max);
    }

    /**
     * What the admin sees: every registered job with its last run, last success and a state.
     *
     * @return list<array<string,mixed>>
     */
    public static function overview(): array
    {
        $now = Clock::now();
        $rows = [];
        foreach (self::REGISTRY as $job => $meta) {
            $last = Db::one('SELECT id, status, trigger_source, dry_run, started_at, finished_at, duration_ms, result_json, error_category, error_message FROM job_runs WHERE job = ? ORDER BY id DESC LIMIT 1', [$job]);
            $lastOk = Db::val("SELECT MAX(started_at) FROM job_runs WHERE job = ? AND status IN ('ok','timeout') AND dry_run = 0", [$job]);
            $fails = (int) Db::val("SELECT COUNT(*) FROM job_runs WHERE job = ? AND status IN ('failed','abandoned') AND started_at >= ?", [$job, Clock::sql($now - 86400)]);
            $runs24 = (int) Db::val('SELECT COUNT(*) FROM job_runs WHERE job = ? AND started_at >= ?', [$job, Clock::sql($now - 86400)]);
            $okTs = $lastOk !== null ? (int) Clock::fromSql((string) $lastOk) : null;
            $state = match (true) {
                $last === null => 'never_run',
                $last['status'] === 'running' && $now - (int) Clock::fromSql((string) $last['started_at']) < $meta['max'] + 120 => 'running',
                in_array($last['status'], ['failed', 'abandoned'], true) => 'failing',
                $okTs === null || $now - $okTs > ($meta['grace'] ?? $meta['every'] * 3 + 60) => 'overdue',
                default => 'ok',
            };
            $rows[] = [
                'job' => $job, 'label' => $meta['label'], 'what' => $meta['what'], 'every_seconds' => $meta['every'], 'dry_run_supported' => in_array($job, self::DRY_RUN, true), 'cron' => $meta['cron'], 'schedule' => isset($meta['daily_at']) ? 'once a day after ' . $meta['daily_at'] . ' (' . Tz::name() . ')' : null, 'state' => $state,
                'last_run' => $last === null ? null : [
                    'status' => $last['status'], 'source' => $last['trigger_source'], 'dry_run' => (bool) $last['dry_run'], 'at' => Clock::iso($last['started_at']),
                    'duration_ms' => $last['duration_ms'] !== null ? (int) $last['duration_ms'] : null,
                    'result' => $last['result_json'] !== null ? json_decode((string) $last['result_json'], true) : null,
                    'error_category' => $last['error_category'], 'error' => $last['error_message'],
                ],
                'last_success_at' => $lastOk !== null ? Clock::iso((string) $lastOk) : null, 'failures_24h' => $fails, 'runs_24h' => $runs24,
            ];
        }
        return $rows;
    }
}
