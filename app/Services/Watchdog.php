<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Request;
use App\Smm\SmmResponse;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Tz;

/**
 * Business Watchdog: the health checks behind Admin → Alerts, run by the `watchdog` job (every 15 minutes by default).
 *
 * Every check reads RECORDS the shop already keeps (payments, webhook deliveries, the calls made to the SMM provider, orders, cost lines, the
 * job history, the application log) and compares them with a threshold the owner can change. It never makes up a reading:
 *
 *   ok            the check ran and found nothing to report
 *   alert         a threshold was crossed — one alert per problem (see Alerts: repeated detections raise the occurrence count, not a new row)
 *   insufficient  there is not enough data to say anything (three payments in an hour, a shop that is two days old …) — NOT "healthy"
 *   unavailable   the thing to look at is not there or cannot be read (no SMM provider, no log folder, a provider that returned no balance)
 *   disabled      switched off in the settings
 *
 * "insufficient" and "unavailable" never raise an alert and never resolve one: the alert (if any) keeps its last-detected time, so nobody is told
 * a problem is over just because nothing could be measured. A problem is resolved automatically only after the check has run clean a
 * configurable number of times in a row (default 2), so a flapping condition does not open and close alerts all day.
 *
 * What kind of check each one is (shown next to it in the admin): `rule` = a fixed rule applied to records; `heuristic` = today compared
 * with recent history (it can be wrong: a quiet Sunday looks like a drop) ; `provider` = asks the provider's DOCUMENTED API (SMM API v2
 * `balance`) and is OFF by default. No external AI model takes part in any of them.
 *
 * What is deliberately NOT checked, because the APIs do not offer it: whether the payment gateway is up (there is no status endpoint — failed
 * payments and bad notifications are measured instead), and whether a provider service is still orderable (the catalogue is not polled).
 */
final class Watchdog
{
    /** key => [label, kind, what it looks at] */
    public const CHECKS = [
        'payment_failures' => ['Payment failures', 'rule', 'The share of finished payments that failed (gateway failure or amount mismatch) in a recent window. Sessions that merely expired are customers who walked away and are not counted.'],
        'webhooks'         => ['Payment notifications', 'rule', 'Notifications from the payment gateway that were rejected (bad signature), could not be processed, did not match the order, or were delivered again and again.'],
        'provider_errors'  => ['SMM provider calls', 'rule', 'The share of the calls the shop made to each SMM provider that failed, split into “could not reach / server error” and “rejected”. Only the shop’s own calls are counted — nothing is assumed about the provider’s status.'],
        'stuck_orders'     => ['Stuck orders', 'rule', 'Paid orders that are not moving: an unknown provider outcome, sending that never finished, AUTO orders nobody picked up, provider orders that are not being followed, manual orders waiting too long.'],
        'provider_cost'    => ['Rising provider cost', 'heuristic', 'The provider’s final charge per 1,000 units of a service compared with the days before. Only orders whose final charge the provider reported are compared.'],
        'missing_costs'    => ['Missing cost data', 'rule', 'Services that sold without a known cost, active services with no cost set, and a payment-fee model that was never set up — profit figures stay incomplete until these are fixed.'],
        'sales_drop'       => ['Drop in verified sales', 'heuristic', 'Verified sales of the last 24 hours compared with the daily average of the days before.'],
        'conversion'       => ['Conversion rate change', 'heuristic', 'Paying visitors per qualified visitor over the last days compared with the period before. Needs enough visitors to mean anything.'],
        'cron_jobs'        => ['Scheduled jobs', 'rule', 'Jobs that keep failing, or that have not completed for much longer than their schedule.'],
        'app_errors'       => ['Application errors', 'rule', 'Errors the application wrote to its own log, and scheduled-job runs that failed on the database. Details stay in the server log; nothing technical is shown here.'],
        'finance'          => ['Financial discrepancies', 'rule', 'Money records that disagree: revenue without a verified payment, a verified payment whose order is not paid, a paid amount that differs from the order, refunds that do not add up.'],
        'provider_balance' => ['Provider balance', 'provider', 'OFF by default. Asks the default SMM provider for its balance with the documented “balance” call (at most once an hour; never in a dry run) and alerts below a minimum you set.'],
        'sales_target'     => ['Sales target pacing', 'rule', 'The current sales target\'s projected finish (App\\Services\\SalesTarget), while it is running or just ended. Nothing to check with no target set.'],
        'ad_budget'        => ['Advertising budget', 'rule', 'Today\'s and this month\'s actual ad spend against the configured daily/monthly budget (App\\Services\\AdBudget). Nothing to check with no cap set.'],
    ];

    /** Checks that start switched off. */
    public const DEFAULT_OFF = ['provider_balance'];

    /**
     * setting => [check ('*' = all), label, unit, default, min, max]. Stored as `watchdog.<setting>`.
     *
     * @var array<string,array{0:string,1:string,2:string,3:int,4:int,5:int}>
     */
    public const FIELDS = [
        'pay_fail_pct'         => ['payment_failures', 'Alert when failures reach', '% of finished payments', 40, 5, 100],
        'pay_fail_min'         => ['payment_failures', 'Only judge with at least', 'finished payments', 5, 2, 1000],
        'pay_fail_window_min'  => ['payment_failures', 'Look back', 'minutes', 60, 15, 1440],
        'webhook_window_min'   => ['webhooks', 'Look back', 'minutes', 60, 15, 1440],
        'webhook_invalid'      => ['webhooks', 'Alert at this many rejected (bad signature)', 'notifications', 10, 1, 10000],
        'webhook_errors'       => ['webhooks', 'Alert at this many unprocessed or mismatched', 'notifications', 3, 1, 1000],
        'webhook_repeats'      => ['webhooks', 'Alert at this many delivered 3 or more times', 'notifications', 5, 1, 1000],
        'provider_window_min'  => ['provider_errors', 'Look back', 'minutes', 60, 15, 1440],
        'provider_min_calls'   => ['provider_errors', 'Only judge a provider with at least', 'calls', 5, 2, 1000],
        'provider_error_pct'   => ['provider_errors', 'Alert when failed calls reach', '% of calls', 50, 10, 100],
        'stuck_minutes'        => ['stuck_orders', 'An AUTO order is stuck after', 'minutes unsent', 15, 5, 1440],
        'manual_wait_hours'    => ['stuck_orders', 'A manual order is waiting too long after', 'hours', 24, 1, 720],
        'cost_rise_pct'        => ['provider_cost', 'Alert when the cost per 1,000 rises by', '%', 20, 5, 500],
        'cost_recent_days'     => ['provider_cost', 'Recent period', 'days', 3, 1, 30],
        'cost_baseline_days'   => ['provider_cost', 'Compared with the period before of', 'days', 14, 3, 90],
        'cost_min_orders'      => ['provider_cost', 'Only compare a service with at least', 'orders in each period', 3, 2, 100],
        'missing_cost_days'    => ['missing_costs', 'Look at paid orders of the last', 'days', 30, 7, 180],
        'sales_drop_pct'       => ['sales_drop', 'Alert when sales fall by', '%', 50, 10, 95],
        'sales_baseline_days'  => ['sales_drop', 'Compared with the average of the previous', 'days', 7, 3, 30],
        'sales_min_orders'     => ['sales_drop', 'Only judge when that average is at least', 'orders a day', 3, 1, 1000],
        'conv_change_pct'      => ['conversion', 'Alert when the rate changes by', '%', 50, 10, 500],
        'conv_recent_days'     => ['conversion', 'Recent period', 'days', 3, 1, 14],
        'conv_baseline_days'   => ['conversion', 'Compared with the period before of', 'days', 14, 3, 60],
        'conv_min_visitors'    => ['conversion', 'Only judge with at least', 'qualified visitors in each period', 100, 20, 100000],
        'job_failures'         => ['cron_jobs', 'Alert when a job fails', 'times', 3, 1, 100],
        'job_failure_window_h' => ['cron_jobs', '…within the last', 'hours', 6, 1, 72],
        'app_error_window_min' => ['app_errors', 'Look back', 'minutes', 60, 15, 1440],
        'app_errors'           => ['app_errors', 'Alert at this many errors', 'errors', 5, 1, 10000],
        'balance_min'          => ['provider_balance', 'Alert when the balance is below', 'in the provider’s own currency (0 = just record it)', 0, 0, 10000000],
        'target_critical_pct'  => ['sales_target', 'Escalate to critical when the projected finish falls below', '% of the target', 75, 10, 94],
        'budget_critical_pct'  => ['ad_budget', 'Escalate to critical once spend reaches this much of the cap', '% of the budget', 150, 105, 1000],
        'clean_runs'           => ['*', 'Resolve an alert automatically after', 'clean runs in a row', 2, 1, 10],
    ];

    private const STATE_KEY = 'watchdog.state';
    private const SEVERITY_RANK = ['info' => 1, 'warning' => 2, 'critical' => 3];

    /** Test seam: fn(array $providerRow): SmmResponse — how the optional balance check asks the provider. Null = the real SMM client. */
    public static $balanceFetcher = null;

    /** Test seam: the folder holding app-YYYY-MM-DD.log. Null = storage/logs. */
    public static ?string $logDir = null;

    // ═══════════════════════════ settings ═══════════════════════════════════

    /** @return array<string,int> */
    public static function thresholds(): array
    {
        $out = [];
        foreach (self::FIELDS as $name => [, , , $default, $min, $max]) {
            $out[$name] = max($min, min($max, (int) Settings::get('watchdog.' . $name, $default)));
        }
        return $out;
    }

    /** @return array<string,bool> check => switched on */
    public static function enabled(): array
    {
        $out = [];
        foreach (array_keys(self::CHECKS) as $key) {
            $out[$key] = (bool) Settings::get('watchdog.enabled.' . $key, !in_array($key, self::DEFAULT_OFF, true));
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $in {thresholds?: {name: int}, enabled?: {check: bool}}
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed> settingsView()
     */
    public static function saveSettings(array $in, array $admin, ?Request $req = null): array
    {
        $errors = [];
        $set = [];
        $changes = [];
        $current = self::thresholds();
        $thresholds = $in['thresholds'] ?? [];
        if (!is_array($thresholds)) {
            throw new HttpException(422, 'Some of the settings are not valid.', 'invalid', ['fields' => ['thresholds' => 'Send the thresholds as a list of numbers.']]);
        }
        foreach ($thresholds as $name => $v) {
            if (!is_string($name) || !isset(self::FIELDS[$name])) {
                continue;
            }
            [, , , , $min, $max] = self::FIELDS[$name];
            if (!is_int($v) && !(is_string($v) && preg_match('/^\d{1,9}$/D', $v))) {
                $errors[$name] = 'Enter a whole number.';
            } elseif ((int) $v < $min || (int) $v > $max) {
                $errors[$name] = "Enter a number from {$min} to {$max}.";
            } elseif ((int) $v !== $current[$name]) {
                $set['watchdog.' . $name] = (int) $v;
                $changes[$name] = [$current[$name], (int) $v];
            }
        }
        $enabled = $in['enabled'] ?? [];
        if (!is_array($enabled)) {
            throw new HttpException(422, 'Some of the settings are not valid.', 'invalid', ['fields' => ['enabled' => 'Send the switches as a list of on / off values.']]);
        }
        $now = self::enabled();
        foreach ($enabled as $check => $v) {
            if (!is_string($check) || !isset(self::CHECKS[$check])) {
                continue;
            }
            if (!is_bool($v)) {
                $errors['enabled.' . $check] = 'Choose on or off.';
            } elseif ($v !== $now[$check]) {
                $set['watchdog.enabled.' . $check] = $v;
                $changes['enabled.' . $check] = [$now[$check], $v];
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Some of the settings are not valid.', 'invalid', ['fields' => $errors]);
        }
        if ($set) {
            Settings::setMany($set, $admin['id']);
            Audit::log('watchdog.settings_changed', 'settings', 'watchdog', ['changed' => $changes], $req, $admin);
        }
        return self::settingsView();
    }

    /** @return array<string,mixed> */
    public static function settingsView(): array
    {
        $values = self::thresholds();
        $fields = [];
        foreach (self::FIELDS as $name => [$check, $label, $unit, $default, $min, $max]) {
            $fields[] = ['name' => $name, 'check' => $check, 'label' => $label, 'unit' => $unit, 'value' => $values[$name], 'default' => $default, 'min' => $min, 'max' => $max];
        }
        return ['fields' => $fields, 'enabled' => self::enabled(), 'checks' => self::checkList()];
    }

    /** @return list<array{key:string,label:string,kind:string,what:string}> */
    private static function checkList(): array
    {
        $out = [];
        foreach (self::CHECKS as $key => [$label, $kind, $what]) {
            $out[] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'what' => $what];
        }
        return $out;
    }

    // ═══════════════════════════ the job ════════════════════════════════════

    /**
     * Run every enabled check, raise / refresh / resolve alerts and remember what each check saw. A dry run evaluates everything and writes
     * nothing (no alert, no e-mail, no stored state, no call to a provider).
     *
     * @return array<string,mixed> counters and the per-check verdicts (kept small: it goes into the job history)
     */
    public static function run(JobContext $c): array
    {
        $write = !$c->dryRun;
        $now = Clock::now();
        $t = self::thresholds();
        $enabled = self::enabled();
        $state = self::state();
        $out = ['checks_run' => 0, 'ok' => 0, 'alerting' => 0, 'no_verdict' => 0, 'disabled' => 0, 'alerts_raised' => 0, 'alerts_updated' => 0, 'alerts_resolved' => 0, 'emails_sent' => 0];
        $verdicts = [];
        $would = [];

        foreach (self::CHECKS as $key => $meta) {
            if (!$enabled[$key]) {
                $out['disabled']++;
                $verdicts[$key] = 'disabled';
                continue;
            }
            if ($c->expired()) {
                $out['stopped_before'] = $key;      // the remaining checks run first next time
                break;
            }
            $r = self::evaluate($key, $now, $t, $write);
            $out['checks_run']++;
            $verdicts[$key] = $r['status'];
            if ($r['status'] === 'ok') {
                $out['ok']++;
            } elseif ($r['status'] === 'alert') {
                $out['alerting']++;
            } else {
                $out['no_verdict']++;
            }

            $current = [];
            foreach ($r['findings'] as $f) {
                $dedupe = self::dedupeKey($key, $f['suffix']);
                $current[$dedupe] = true;
                if (!$write) {
                    $would[] = $dedupe;
                    continue;
                }
                $a = Alerts::raise($key, $f['severity'], $f['title'], $f['text'], [
                    'entity_type' => $f['entity_type'] ?? null, 'entity_id' => $f['entity_id'] ?? null, 'dedupe_key' => $dedupe, 'details' => self::safe($f['details'] ?? []),
                ]);
                $out[$a['created'] ? 'alerts_raised' : 'alerts_updated']++;
                $out['emails_sent'] += $a['notified'] ? 1 : 0;
            }

            // Quiet-run bookkeeping — only when the check really reached a verdict. No data is not evidence that a problem is over.
            $absent = [];
            if (in_array($r['status'], ['ok', 'alert'], true)) {
                $live = Db::col("SELECT active_key FROM business_alerts WHERE alert_type = ? AND active_key LIKE 'wd:%'", [$key]);
                $before = $state[$key]['absent'] ?? [];
                foreach ($live as $activeKey) {
                    if (isset($current[$activeKey])) {
                        continue;
                    }
                    $quiet = (int) ($before[$activeKey] ?? 0) + 1;
                    if ($quiet >= $t['clean_runs']) {
                        if ($write && Alerts::clear($activeKey)) {
                            $out['alerts_resolved']++;
                        }
                    } else {
                        $absent[$activeKey] = $quiet;
                    }
                }
            } else {
                $absent = $state[$key]['absent'] ?? [];
            }

            $top = null;
            foreach ($r['findings'] as $f) {
                if ($top === null || self::SEVERITY_RANK[$f['severity']] > self::SEVERITY_RANK[$top]) {
                    $top = $f['severity'];
                }
            }
            $state[$key] = ['status' => $r['status'], 'at' => $now, 'summary' => mb_strimwidth($r['summary'], 0, 500, '…'), 'values' => self::safe($r['values']), 'severity' => $top, 'absent' => $absent];
        }

        if ($write) {
            Settings::set(self::STATE_KEY, $state);
        } else {
            $out['would_raise_or_refresh'] = count($would);
        }
        return $out + ['verdicts' => $verdicts];
    }

    /** `wd:<check>` or `wd:<check>:<entity>`: the dedupe key of an alert this class owns. */
    private static function dedupeKey(string $check, string $suffix): string
    {
        return 'wd:' . $check . ($suffix !== '' ? ':' . $suffix : '');
    }

    /**
     * What each check saw the last time the job ran, for the Alerts screen.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function state(): array
    {
        $s = Settings::get(self::STATE_KEY, []);
        return is_array($s) ? $s : [];
    }

    /** @return array<string,mixed> */
    public static function overview(): array
    {
        $state = self::state();
        $enabled = self::enabled();
        $values = self::thresholds();
        $liveBy = [];
        foreach (Db::all("SELECT alert_type, COUNT(*) AS n FROM business_alerts WHERE status IN ('open', 'acknowledged') GROUP BY alert_type") as $r) {
            $liveBy[(string) $r['alert_type']] = (int) $r['n'];
        }
        $checks = [];
        foreach (self::CHECKS as $key => [$label, $kind, $what]) {
            $s = $state[$key] ?? null;
            $fields = [];
            foreach (self::FIELDS as $name => [$check, $fieldLabel, $unit]) {
                if ($check === $key) {
                    $fields[] = ['name' => $name, 'label' => $fieldLabel, 'unit' => $unit, 'value' => $values[$name]];
                }
            }
            $checks[] = [
                'key' => $key, 'label' => $label, 'kind' => $kind, 'what' => $what, 'enabled' => $enabled[$key],
                'status' => !$enabled[$key] ? 'disabled' : ($s['status'] ?? 'never'),
                'summary' => !$enabled[$key] ? 'Switched off in the settings.' : ($s['summary'] ?? 'Has not run yet.'),
                'checked_at' => isset($s['at']) ? gmdate('c', (int) $s['at']) : null,
                'live_alerts' => $liveBy[$key] ?? 0, 'fields' => $fields,
            ];
        }
        $job = null;
        foreach (Jobs::overview() as $j) {
            if ($j['job'] === 'watchdog') {
                $job = $j;
            }
        }
        return ['checks' => $checks, 'job' => $job, 'counts' => Alerts::counts()];
    }

    // ═══════════════════════════ evaluating one check ═══════════════════════

    /**
     * Evaluate one check and say what it found. Reads only; never raises an alert (run() does). An exception inside a check becomes an
     * "unavailable" verdict, so one broken check cannot stop the others.
     *
     * @param array<string,int>|null $t thresholds (defaults to the stored ones)
     * @param bool $live false in a dry run: a check that would call out to a provider does not
     * @return array{status:string,summary:string,findings:list<array<string,mixed>>,values:array<string,mixed>}
     */
    public static function evaluate(string $check, ?int $now = null, ?array $t = null, bool $live = true): array
    {
        if (!isset(self::CHECKS[$check])) {
            throw new \InvalidArgumentException("Unknown watchdog check '{$check}'.");
        }
        $now ??= Clock::now();
        $t ??= self::thresholds();
        try {
            return match ($check) {
                'payment_failures' => self::paymentFailures($now, $t),
                'webhooks'         => self::webhooks($now, $t),
                'provider_errors'  => self::providerErrors($now, $t),
                'stuck_orders'     => self::stuckOrders($now, $t),
                'provider_cost'    => self::providerCost($now, $t),
                'missing_costs'    => self::missingCosts($now, $t),
                'sales_drop'       => self::salesDrop($now, $t),
                'conversion'       => self::conversion($now, $t),
                'cron_jobs'        => self::cronJobs($now, $t),
                'app_errors'       => self::appErrors($now, $t),
                'finance'          => self::finance($now, $t),
                'provider_balance' => self::providerBalance($now, $t, $live),
                'sales_target'     => self::salesTargetPacing($now, $t),
                'ad_budget'        => self::adBudget($now, $t),
            };
        } catch (\Throwable $e) {
            Log::exception($e, 'Watchdog check failed', ['check' => $check]);
            return self::noVerdict('unavailable', 'The check could not run: ' . Jobs::classify($e)[1]);
        }
    }

    // ── result constructors ──

    /** @param array<string,mixed> $values @return array{status:string,summary:string,findings:list<array<string,mixed>>,values:array<string,mixed>} */
    private static function healthy(string $summary, array $values = []): array
    {
        return ['status' => 'ok', 'summary' => $summary, 'findings' => [], 'values' => $values];
    }

    /** @param array<string,mixed> $values */
    private static function noVerdict(string $status, string $summary, array $values = []): array
    {
        return ['status' => $status, 'summary' => $summary, 'findings' => [], 'values' => $values];
    }

    /**
     * @param list<array<string,mixed>> $findings each: suffix, severity, title, text, entity_type?, entity_id?, details?
     * @param array<string,mixed> $values
     */
    private static function alerting(string $summary, array $findings, array $values = []): array
    {
        return ['status' => 'alert', 'summary' => $summary, 'findings' => $findings, 'values' => $values];
    }

    private static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }

    // ── 1. payment failures ──

    private static function paymentFailures(int $now, array $t): array
    {
        $win = $t['pay_fail_window_min'];
        $r = Db::one(
            'SELECT SUM(CASE WHEN paid_at IS NOT NULL THEN 1 ELSE 0 END) AS paid_n, '
            . "SUM(CASE WHEN paid_at IS NULL AND status IN ('failed', 'mismatch') THEN 1 ELSE 0 END) AS bad_n, "
            . "SUM(CASE WHEN paid_at IS NULL AND status = 'expired' THEN 1 ELSE 0 END) AS expired_n FROM payments WHERE created_at >= ?",
            [Clock::sql($now - $win * 60)]
        ) ?? [];
        $paid = (int) ($r['paid_n'] ?? 0);
        $bad = (int) ($r['bad_n'] ?? 0);
        $expired = (int) ($r['expired_n'] ?? 0);
        $finished = $paid + $bad;
        $values = ['window_min' => $win, 'paid' => $paid, 'failed' => $bad, 'expired' => $expired];
        if ($finished < $t['pay_fail_min']) {
            return self::noVerdict('insufficient', 'Only ' . self::plural($finished, 'payment') . " finished in the last {$win} minutes; at least {$t['pay_fail_min']} are needed to judge a failure rate.", $values);
        }
        $tenths = Money::pctTenths($bad, $finished);
        $values['failure_pct'] = $tenths / 10;
        $pct = Money::tenthsToString($tenths);
        if ($tenths < $t['pay_fail_pct'] * 10) {
            return self::healthy("{$bad} of {$finished} finished payments failed in the last {$win} minutes ({$pct}%), under the {$t['pay_fail_pct']}% limit.", $values);
        }
        $critical = $tenths >= min(1000, $t['pay_fail_pct'] * 20);
        return self::alerting("{$bad} of {$finished} finished payments failed in the last {$win} minutes ({$pct}%).", [[
            'suffix' => '', 'severity' => $critical ? 'critical' : 'warning', 'title' => 'Many payments are failing',
            'text' => "{$bad} of {$finished} payments that finished in the last {$win} minutes failed ({$pct}%; the limit is {$t['pay_fail_pct']}%). "
                . self::plural($expired, 'more session') . ' expired unpaid, which is normal when customers walk away and is not counted. Check Payments and the payment gateway’s own dashboard.',
            'details' => $values,
        ]], $values);
    }

    // ── 2. payment notifications ──

    private static function webhooks(int $now, array $t): array
    {
        $win = $t['webhook_window_min'];
        $since = Clock::sql($now - $win * 60);
        $r = Db::one(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN signature_valid = 0 OR status = 'invalid' THEN 1 ELSE 0 END) AS invalid_n, "
            . "SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error_n, SUM(CASE WHEN status = 'mismatch' THEN 1 ELSE 0 END) AS mismatch_n FROM webhook_events WHERE received_at >= ?",
            [$since]
        ) ?? [];
        $repeats = (int) Db::val('SELECT COUNT(*) FROM webhook_events WHERE signature_valid = 1 AND attempts >= 3 AND COALESCE(last_attempt_at, received_at) >= ?', [$since]);
        $total = (int) ($r['total'] ?? 0);
        $invalid = (int) ($r['invalid_n'] ?? 0);
        $errors = (int) ($r['error_n'] ?? 0);
        $mismatch = (int) ($r['mismatch_n'] ?? 0);
        $unprocessed = $errors + $mismatch;
        $values = ['window_min' => $win, 'received' => $total, 'invalid_signature' => $invalid, 'unprocessed' => $errors, 'mismatch' => $mismatch, 'repeated' => $repeats];

        $parts = [];
        $critical = false;
        if ($invalid >= $t['webhook_invalid']) {
            $parts[] = self::plural($invalid, 'notification') . ' failed signature verification and ' . ($invalid === 1 ? 'was' : 'were') . ' rejected';
        }
        if ($unprocessed >= $t['webhook_errors']) {
            $parts[] = self::plural($errors, 'notification') . ' could not be processed and ' . self::plural($mismatch, 'did', 'did') . ' not match the order';
            $critical = $unprocessed >= 2 * $t['webhook_errors'];
        }
        if ($repeats >= $t['webhook_repeats']) {
            $parts[] = self::plural($repeats, 'notification') . ' ' . ($repeats === 1 ? 'was' : 'were') . ' delivered three or more times';
        }
        if (!$parts) {
            return self::healthy($total === 0 ? "No payment notifications arrived in the last {$win} minutes." : "{$total} notification" . ($total === 1 ? '' : 's') . " in the last {$win} minutes, none over a limit.", $values);
        }
        return self::alerting('In the last ' . $win . ' minutes: ' . implode('; ', $parts) . '.', [[
            'suffix' => '', 'severity' => $critical ? 'critical' : 'warning', 'title' => 'Payment notifications are going wrong',
            'text' => "In the last {$win} minutes: " . implode('; ', $parts) . '. A rejected notification never marks an order paid. Open Payment checks for the details.',
            'details' => $values,
        ]], $values);
    }

    // ── 3. SMM provider calls ──

    private static function providerErrors(int $now, array $t): array
    {
        $win = $t['provider_window_min'];
        $rows = Db::all(
            'SELECT x.provider_id AS id, MAX(p.name) AS name, COUNT(*) AS n, SUM(CASE WHEN x.ok = 0 THEN 1 ELSE 0 END) AS bad, '
            . 'SUM(CASE WHEN x.ok = 0 AND (x.http_status IS NULL OR x.http_status >= 500 OR x.error LIKE \'The provider returned a non-JSON%\') THEN 1 ELSE 0 END) AS technical '
            . "FROM smm_transactions x JOIN smm_providers p ON p.id = x.provider_id WHERE x.created_at >= ? AND x.action IN ('add', 'status') GROUP BY x.provider_id ORDER BY x.provider_id",
            [Clock::sql($now - $win * 60)]
        );
        if (!$rows) {
            return self::noVerdict('insufficient', "The shop made no call to an SMM provider in the last {$win} minutes, so there is nothing to judge.", ['window_min' => $win]);
        }
        $findings = [];
        $judged = 0;
        $values = ['window_min' => $win, 'providers' => []];
        foreach ($rows as $r) {
            $n = (int) $r['n'];
            $bad = (int) $r['bad'];
            $technical = (int) $r['technical'];
            $values['providers'][(string) $r['id']] = ['calls' => $n, 'failed' => $bad, 'technical' => $technical];
            if ($n < $t['provider_min_calls']) {
                continue;
            }
            $judged++;
            $tenths = Money::pctTenths($bad, $n);
            if ($tenths < $t['provider_error_pct'] * 10) {
                continue;
            }
            $name = (string) $r['name'];
            $findings[] = [
                'suffix' => (string) $r['id'], 'entity_type' => 'provider', 'entity_id' => (string) $r['id'],
                'severity' => $tenths >= 900 ? 'critical' : 'warning', 'title' => 'SMM provider “' . $name . '” is failing',
                'text' => "{$bad} of {$n} calls to “{$name}” failed in the last {$win} minutes (" . Money::tenthsToString($tenths) . '%): '
                    . "{$technical} could not reach the provider or got a server error, " . ($bad - $technical) . ' were rejected by it (for example a bad link, no funds, an inactive service). '
                    . 'Use “Test connection” on the SMM API page and check the provider’s own panel.',
                'details' => ['provider_id' => (int) $r['id'], 'calls' => $n, 'failed' => $bad, 'technical' => $technical, 'rejected' => $bad - $technical],
            ];
        }
        if ($judged === 0) {
            return self::noVerdict('insufficient', "No provider had {$t['provider_min_calls']} calls in the last {$win} minutes, so no failure rate was judged.", $values);
        }
        if (!$findings) {
            return self::healthy(self::plural($judged, 'provider') . " judged over the last {$win} minutes; none is over the {$t['provider_error_pct']}% limit.", $values);
        }
        return self::alerting(self::plural(count($findings), 'provider') . " failing in the last {$win} minutes.", $findings, $values);
    }

    // ── 4. stuck orders ──

    private static function stuckOrders(int $now, array $t): array
    {
        $c = Recovery::summary()['counts'];
        $unsent = (int) Db::val(
            "SELECT COUNT(*) FROM orders o WHERE o.mode = 'AUTO' AND o.status = 'PAID' AND o.paid_at IS NOT NULL AND o.provider_order_id IS NULL AND o.paid_at < ? "
            . "AND (o.next_attempt_at IS NULL OR o.next_attempt_at <= ?) AND COALESCE(o.recovery_status, '') NOT IN ('needs_review', 'escalated')",
            [Clock::sql($now - $t['stuck_minutes'] * 60), Clock::sql($now)]
        );
        $manual = (int) Db::val(
            "SELECT COUNT(*) FROM orders WHERE mode = 'MANUAL' AND status = 'MANUAL_PENDING' AND paid_at IS NOT NULL AND paid_at < ?",
            [Clock::sql($now - $t['manual_wait_hours'] * 3600)]
        );
        $values = ['unknown_outcome' => $c['unknown'], 'sending_stuck' => $c['sending'], 'unsent' => $unsent, 'needs_review' => $c['review'], 'failed' => $c['failed'], 'status_errors' => $c['errors'], 'not_followed' => $c['stale'], 'manual_waiting' => $manual];
        $parts = [];
        if ($c['unknown'] > 0) {
            $parts[] = self::plural($c['unknown'], 'order') . ' with an unknown provider outcome (the provider may already have it — never resend before checking its panel)';
        }
        if ($c['sending'] > 0) {
            $parts[] = self::plural($c['sending'], 'order') . ' claimed for sending with no answer recorded for over ' . intdiv(Recovery::SENDING_STUCK_AFTER, 60) . ' minutes';
        }
        if ($unsent > 0) {
            $parts[] = self::plural($unsent, 'paid AUTO order') . " not sent after {$t['stuck_minutes']} minutes (is the cron job running?)";
        }
        if ($c['failed'] > 0 || $c['review'] > 0) {
            $parts[] = self::plural($c['failed'] + $c['review'], 'order') . ' that ' . ($c['failed'] + $c['review'] === 1 ? 'needs' : 'need') . ' a person (failed or marked for review)';
        }
        if ($c['errors'] > 0 || $c['stale'] > 0) {
            $parts[] = self::plural($c['errors'] + $c['stale'], 'provider order') . ' whose status is not being refreshed';
        }
        if ($manual > 0) {
            $parts[] = self::plural($manual, 'manual order') . " waiting more than {$t['manual_wait_hours']} hours for approval";
        }
        if (!$parts) {
            return self::healthy('No paid order is stuck.', $values);
        }
        $critical = $c['unknown'] > 0 || $c['sending'] > 0;
        return self::alerting(implode('; ', $parts) . '.', [[
            'suffix' => '', 'severity' => $critical ? 'critical' : 'warning', 'title' => 'Paid orders are stuck',
            'text' => ucfirst(implode('; ', $parts)) . '. Open Order recovery.', 'details' => $values,
        ]], $values);
    }

    // ── 5. rising provider cost ──

    private static function providerCost(int $now, array $t): array
    {
        $recent = $t['cost_recent_days'];
        $base = $t['cost_baseline_days'];
        $cut = Clock::sql($now - $recent * 86400);
        $rows = Db::all(
            'SELECT o.service_id AS id, MAX(o.service_title) AS title, '
            . 'SUM(CASE WHEN o.paid_at >= ? THEN 1 ELSE 0 END) AS n_new, SUM(CASE WHEN o.paid_at >= ? THEN o.provider_cost_paise ELSE 0 END) AS cost_new, SUM(CASE WHEN o.paid_at >= ? THEN o.quantity ELSE 0 END) AS qty_new, '
            . 'SUM(CASE WHEN o.paid_at < ? THEN 1 ELSE 0 END) AS n_old, SUM(CASE WHEN o.paid_at < ? THEN o.provider_cost_paise ELSE 0 END) AS cost_old, SUM(CASE WHEN o.paid_at < ? THEN o.quantity ELSE 0 END) AS qty_old '
            . "FROM orders o WHERE o.mode = 'AUTO' AND o.provider_cost_final = 1 AND o.provider_cost_paise > 0 AND o.quantity > 0 AND o.paid_at IS NOT NULL AND o.paid_at >= ? GROUP BY o.service_id",
            [$cut, $cut, $cut, $cut, $cut, $cut, Clock::sql($now - ($recent + $base) * 86400)]
        );
        $compared = 0;
        $risen = [];
        foreach ($rows as $r) {
            if ((int) $r['n_new'] < $t['cost_min_orders'] || (int) $r['n_old'] < $t['cost_min_orders'] || (int) $r['qty_new'] <= 0 || (int) $r['qty_old'] <= 0) {
                continue;
            }
            $compared++;
            $new = Money::mulDiv((int) $r['cost_new'], 1000, (int) $r['qty_new']);
            $old = Money::mulDiv((int) $r['cost_old'], 1000, (int) $r['qty_old']);
            if ($old > 0 && $new * 100 >= $old * (100 + $t['cost_rise_pct'])) {
                $risen[] = ['service' => (string) $r['title'], 'from' => $old, 'to' => $new, 'pct_tenths' => Money::pctTenths($new - $old, $old)];
            }
        }
        $values = ['compared_services' => $compared, 'risen_services' => count($risen), 'recent_days' => $recent, 'baseline_days' => $base];
        if ($compared === 0) {
            return self::noVerdict('insufficient', "No service has {$t['cost_min_orders']} orders with a final provider charge in both the last {$recent} days and the {$base} days before, so costs cannot be compared yet.", $values);
        }
        if (!$risen) {
            return self::healthy(self::plural($compared, 'service') . " compared; none costs {$t['cost_rise_pct']}% or more per 1,000 than before.", $values);
        }
        usort($risen, static fn(array $a, array $b): int => $b['pct_tenths'] <=> $a['pct_tenths']);
        $lines = array_map(static fn(array $x): string => '“' . $x['service'] . '” ' . Money::inr($x['from'], true) . ' → ' . Money::inr($x['to'], true) . ' (+' . Money::tenthsToString($x['pct_tenths']) . '%)', array_slice($risen, 0, 3));
        return self::alerting(self::plural(count($risen), 'service') . ' cost more per 1,000 than before.', [[
            'suffix' => '', 'severity' => 'warning', 'title' => 'The provider’s cost per 1,000 has risen',
            'text' => 'The provider’s final charge per 1,000 over the last ' . $recent . ' days is ' . $t['cost_rise_pct'] . '% or more above the ' . $base . ' days before for ' . self::plural(count($risen), 'service') . ': '
                . implode('; ', $lines) . '. Compare with the provider’s rate list, then update the service cost and price.',
            'details' => $values + ['top' => array_slice($risen, 0, 3)],
        ]], $values);
    }

    // ── 6. missing cost data ──

    private static function missingCosts(int $now, array $t): array
    {
        $to = Tz::today();
        $from = Tz::addDays($to, -($t['missing_cost_days'] - 1));
        // The same test as Finance::incompleteSql() without the payment-fee clause (that one is reported on its own, below).
        $unknown = Db::all(
            'SELECT o.service_id AS id, MAX(o.service_title) AS title, COUNT(*) AS n FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ? AND ('
            . "(o.mode = 'AUTO' AND o.provider_cost_final = 0 AND o.provider_cost_paise = 0 AND NOT (o.provider_order_id IS NULL AND o.status IN ('FAILED', 'CANCELLED', 'REFUNDED', 'EXPIRED'))) "
            . "OR (o.mode = 'MANUAL' AND NOT EXISTS (SELECT 1 FROM order_costs fc WHERE fc.order_id = o.id AND fc.cost_type = 'fulfilment' AND fc.superseded_at IS NULL))"
            . ') GROUP BY o.service_id ORDER BY n DESC, o.service_id LIMIT 50',
            [$from, $to]
        );
        $unknownOrders = array_sum(array_map(static fn(array $r): int => (int) $r['n'], $unknown));
        $unconfigured = array_values(array_filter(
            Db::all("SELECT id, title, mode, smm_cost_paise, fulfil_cost_paise FROM services WHERE deleted_at IS NULL AND status = 'active' ORDER BY platform_id, sort_order, id"),
            static fn(array $s): bool => !($s['mode'] === 'AUTO' ? (int) $s['smm_cost_paise'] > 0 : $s['fulfil_cost_paise'] !== null)
        ));
        $paidInWindow = (int) Db::val('SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL AND paid_day BETWEEN ? AND ?', [$from, $to]);
        $feeUnset = Finance::settings()['fee_mode'] === 'unset' && $paidInWindow > 0;
        $values = ['window_days' => $t['missing_cost_days'], 'orders_unknown_cost' => $unknownOrders, 'services_unconfigured' => count($unconfigured), 'fee_model_unset' => $feeUnset];

        if ($unknownOrders === 0 && !$unconfigured && !$feeUnset) {
            return self::healthy('Every active service has a cost and no recent paid order has an unknown cost.', $values);
        }
        $parts = [];
        if ($unknownOrders > 0) {
            $names = array_map(static fn(array $r): string => '“' . $r['title'] . '” ×' . $r['n'], array_slice($unknown, 0, 3));
            $parts[] = self::plural($unknownOrders, 'paid order') . ' of the last ' . $t['missing_cost_days'] . ' days ' . ($unknownOrders === 1 ? 'has' : 'have') . ' an unknown cost (' . implode(', ', $names) . (count($unknown) > 3 ? ', …' : '') . ')';
        }
        if ($unconfigured) {
            $names = array_map(static fn(array $s): string => '“' . $s['title'] . '”', array_slice($unconfigured, 0, 4));
            $parts[] = self::plural(count($unconfigured), 'active service') . ' ' . (count($unconfigured) === 1 ? 'has' : 'have') . ' no cost set (' . implode(', ', $names) . (count($unconfigured) > 4 ? ', …' : '') . ')';
        }
        if ($feeUnset) {
            $parts[] = 'the payment fee model is not set up, so no order’s profit can be complete (choose “No fee” under Finance → Settings if the gateway charges nothing)';
        }
        return self::alerting(ucfirst(implode('; ', $parts)) . '.', [[
            'suffix' => '', 'severity' => ($unknownOrders > 0 || $feeUnset) ? 'warning' : 'info', 'title' => 'Cost data is missing',
            'text' => ucfirst(implode('; ', $parts)) . '. Profit figures are labelled incomplete until this is fixed — see Finance → Reconciliation.',
            'details' => $values,
        ]], $values);
    }

    // ── 7. drop in verified sales ──

    private static function salesDrop(int $now, array $t): array
    {
        $first = Analytics::firstDay();
        $history = max(0, Tz::daysBetween($first, Tz::today()));
        $baseDays = min($t['sales_baseline_days'], $history);
        if ($baseDays < 3) {
            return self::noVerdict('insufficient', 'The shop has less than 3 days of history, so there is no usual level to compare with yet.', ['history_days' => $history]);
        }
        $recentFrom = $now - 86400;
        $sum = static fn(int $from, int $to): array => Db::one(
            'SELECT COUNT(*) AS n, COALESCE(SUM(amount_paise), 0) AS rev FROM orders WHERE paid_at IS NOT NULL AND paid_at >= ? AND paid_at < ?',
            [Clock::sql($from), Clock::sql($to)]
        ) ?? ['n' => 0, 'rev' => 0];
        $recent = $sum($recentFrom, $now + 1);
        $baseline = $sum($recentFrom - $baseDays * 86400, $recentFrom);
        $avgOrders10 = intdiv((int) $baseline['n'] * 10, $baseDays);          // orders a day, in tenths
        $avgRev = intdiv((int) $baseline['rev'], $baseDays);
        $values = ['baseline_days' => $baseDays, 'recent_orders' => (int) $recent['n'], 'recent_paise' => (int) $recent['rev'], 'baseline_orders_per_day_tenths' => $avgOrders10, 'baseline_paise_per_day' => $avgRev];
        if ($avgOrders10 < $t['sales_min_orders'] * 10 || $avgRev <= 0) {
            return self::noVerdict('insufficient', "The previous {$baseDays} days averaged " . Money::tenthsToString($avgOrders10) . " orders a day; at least {$t['sales_min_orders']} are needed to judge a drop.", $values);
        }
        $recentRev = (int) $recent['rev'];
        if ($recentRev >= $avgRev) {
            return self::healthy('Verified sales of the last 24 hours: ' . Money::inr($recentRev, true) . ' (' . self::plural((int) $recent['n'], 'order') . '), at or above the usual ' . Money::inr($avgRev, true) . ' a day.', $values);
        }
        $drop = Money::pctTenths($avgRev - $recentRev, $avgRev);
        $values['drop_pct'] = $drop / 10;
        $summary = 'Verified sales of the last 24 hours: ' . Money::inr($recentRev, true) . ' (' . self::plural((int) $recent['n'], 'order') . '), ' . Money::tenthsToString($drop) . '% below the usual ' . Money::inr($avgRev, true) . ' a day.';
        if ($drop < $t['sales_drop_pct'] * 10) {
            return self::healthy($summary . " Under the {$t['sales_drop_pct']}% limit.", $values);
        }
        return self::alerting($summary, [[
            'suffix' => '', 'severity' => $drop >= min(1000, ($t['sales_drop_pct'] + 30) * 10) ? 'critical' : 'warning', 'title' => 'Verified sales have dropped',
            'text' => $summary . " The usual level is the average of the previous {$baseDays} days (" . Money::tenthsToString($avgOrders10) . ' orders a day). A quiet day of the week, a paused campaign or a payment problem can all cause this — check Payments and the payment gateway first.',
            'details' => $values,
        ]], $values);
    }

    // ── 8. conversion rate change ──

    private static function conversion(int $now, array $t): array
    {
        $recentTo = Tz::addDays(Tz::today(), -1);
        $recentFrom = Tz::addDays($recentTo, -($t['conv_recent_days'] - 1));
        $baseTo = Tz::addDays($recentFrom, -1);
        $baseFrom = Tz::addDays($baseTo, -($t['conv_baseline_days'] - 1));
        $rec = Analytics::sum(Analytics::computeDays($recentFrom, $recentTo));
        $base = Analytics::sum(Analytics::computeDays($baseFrom, $baseTo));
        $values = ['recent_qualified' => $rec['qualified_visitors'], 'recent_paying' => $rec['paid_visitors'], 'baseline_qualified' => $base['qualified_visitors'], 'baseline_paying' => $base['paid_visitors']];
        if ($rec['qualified_visitors'] < $t['conv_min_visitors'] || $base['qualified_visitors'] < $t['conv_min_visitors']) {
            return self::noVerdict('insufficient', "Needs at least {$t['conv_min_visitors']} qualified visitors in each period; there were {$rec['qualified_visitors']} in the last {$t['conv_recent_days']} days and {$base['qualified_visitors']} in the {$t['conv_baseline_days']} days before.", $values);
        }
        $recRate = Money::pctTenths($rec['paid_visitors'], $rec['qualified_visitors']);
        $baseRate = Money::pctTenths($base['paid_visitors'], $base['qualified_visitors']);
        $values += ['recent_rate_tenths' => $recRate, 'baseline_rate_tenths' => $baseRate];
        if ($baseRate <= 0) {
            return self::noVerdict('insufficient', 'Nobody paid in the comparison period, so there is no usual conversion rate to compare with.', $values);
        }
        $change = Money::mulDiv($recRate - $baseRate, 1000, $baseRate);       // relative change, in tenths of a percent
        $values['change_pct'] = $change / 10;
        $said = Money::tenthsToString($recRate) . '% now against ' . Money::tenthsToString($baseRate) . '% before';
        if (abs($change) < $t['conv_change_pct'] * 10) {
            return self::healthy("Conversion {$said} (" . ($change >= 0 ? '+' : '') . Money::tenthsToString($change) . '%), within the ' . $t['conv_change_pct'] . '% limit.', $values);
        }
        $up = $change > 0;
        return self::alerting("Conversion {$said} (" . ($up ? '+' : '') . Money::tenthsToString($change) . '%).', [[
            'suffix' => $up ? 'up' : 'down', 'severity' => $up ? 'info' : 'warning', 'title' => $up ? 'Conversion rate rose sharply' : 'Conversion rate fell sharply',
            'text' => "Paying visitors per qualified visitor: {$said} (" . ($up ? '+' : '') . Money::tenthsToString($change) . '%), comparing the last ' . $t['conv_recent_days'] . ' days with the ' . $t['conv_baseline_days'] . ' before. '
                . ($up ? 'A rise this large is more often a tracking or traffic-mix change than a real improvement — worth a look.'
                       : 'This does not say why: check the payment page, the gateway and where the traffic comes from. Two numbers moving together is not proof of cause.'),
            'details' => $values,
        ]], $values);
    }

    // ── 9. scheduled jobs ──

    private static function cronJobs(int $now, array $t): array
    {
        $since = Clock::sql($now - $t['job_failure_window_h'] * 3600);
        $findings = [];
        $overdue = [];
        $judged = 0;
        $values = ['jobs' => []];
        foreach (Jobs::overview() as $j) {
            $job = (string) $j['job'];
            if ($job === 'watchdog') {
                continue;                   // this run is the watchdog; if it were not running, nobody would read this
            }
            if ($j['state'] !== 'never_run') {
                $judged++;
            }
            $fails = (int) Db::val("SELECT COUNT(*) FROM job_runs WHERE job = ? AND dry_run = 0 AND status IN ('failed', 'abandoned') AND started_at >= ?", [$job, $since]);
            $lastStatuses = Db::col('SELECT status FROM job_runs WHERE job = ? AND dry_run = 0 ORDER BY id DESC LIMIT ' . (int) $t['job_failures'], [$job]);
            $consecutive = count($lastStatuses) >= $t['job_failures'] && !array_diff($lastStatuses, ['failed', 'abandoned']);
            $values['jobs'][$job] = ['state' => $j['state'], 'failures' => $fails, 'consecutive_failures' => $consecutive];
            if ($fails >= $t['job_failures']) {
                $findings[] = [
                    'suffix' => 'failed:' . $job, 'entity_type' => 'job', 'entity_id' => $job, 'severity' => $consecutive ? 'critical' : 'warning', 'title' => '“' . $j['label'] . '” keeps failing',
                    'text' => 'The job “' . $j['label'] . '” failed ' . self::plural($fails, 'time') . ' in the last ' . $t['job_failure_window_h'] . ' hours' . ($consecutive ? ', including its last ' . $t['job_failures'] . ' runs in a row' : '')
                        . ($j['last_run']['error'] ?? null ? '. Last error (' . ($j['last_run']['error_category'] ?? 'unknown') . '): ' . $j['last_run']['error'] : '') . '. Details are in the server log.',
                    'details' => ['job' => $job, 'failures' => $fails, 'window_hours' => $t['job_failure_window_h']],
                ];
            }
            if ($j['state'] === 'overdue') {
                $overdue[] = $j;
                $findings[] = [
                    'suffix' => 'overdue:' . $job, 'entity_type' => 'job', 'entity_id' => $job, 'severity' => 'warning', 'title' => '“' . $j['label'] . '” is not running on schedule',
                    'text' => 'The job “' . $j['label'] . '” has not completed since ' . ($j['last_success_at'] ?? 'it was set up') . ' although it should run about every ' . self::interval((int) $j['every_seconds']) . '. Check the cron job in cPanel (Admin → System shows the command).',
                    'details' => ['job' => $job, 'last_success_at' => $j['last_success_at']],
                ];
            }
        }
        if ($judged === 0) {
            return self::noVerdict('insufficient', 'No scheduled job has run yet, so there is no history to judge.', $values);
        }
        if (!$findings) {
            return self::healthy(self::plural($judged, 'job') . ' with a run history, none failing or overdue.', $values);
        }
        return self::alerting(self::plural(count($findings), 'problem') . ' with scheduled jobs.', $findings, $values);
    }

    private static function interval(int $seconds): string
    {
        return match (true) {
            $seconds >= 86400 => 'day',
            $seconds >= 3600 => (int) round($seconds / 3600) . ' hour' . ((int) round($seconds / 3600) === 1 ? '' : 's'),
            default => max(1, (int) round($seconds / 60)) . ' minutes',
        };
    }

    // ── 10. application errors ──

    private static function appErrors(int $now, array $t): array
    {
        $win = $t['app_error_window_min'];
        $since = $now - $win * 60;
        $log = self::logErrors($since, $now);
        if ($log === null) {
            return self::noVerdict('unavailable', 'The application log folder (storage/logs) does not exist or cannot be read, so errors cannot be counted.');
        }
        $dbRuns = (int) Db::val("SELECT COUNT(*) FROM job_runs WHERE dry_run = 0 AND error_category = 'database' AND started_at >= ?", [Clock::sql($since)]);
        $total = $log['count'] + $dbRuns;
        $values = ['window_min' => $win, 'logged_errors' => $log['count'], 'job_database_errors' => $dbRuns];
        if ($total < $t['app_errors']) {
            return self::healthy("{$total} error" . ($total === 1 ? '' : 's') . " in the last {$win} minutes ({$log['count']} logged, {$dbRuns} database " . ($dbRuns === 1 ? 'failure' : 'failures') . " in scheduled jobs), under the limit of {$t['app_errors']}.", $values);
        }
        $common = [];
        foreach ($log['top'] as $message => $n) {
            $common[] = '“' . $message . '” ×' . $n;
        }
        return self::alerting("{$total} errors in the last {$win} minutes.", [[
            'suffix' => '', 'severity' => $total >= 4 * $t['app_errors'] ? 'critical' : 'warning', 'title' => 'The application is logging errors',
            'text' => "{$log['count']} error" . ($log['count'] === 1 ? ' was' : 's were') . " written to the application log in the last {$win} minutes and {$dbRuns} scheduled-job run" . ($dbRuns === 1 ? '' : 's') . ' failed on the database'
                . ($common ? '. Most frequent: ' . implode(', ', $common) : '') . '. The details (without any customer data) are in storage/logs on the server.',
            'details' => $values,
        ]], $values);
    }

    /** @return array{count:int,top:array<string,int>}|null null when the log folder cannot be read */
    private static function logErrors(int $since, int $now): ?array
    {
        $dir = self::$logDir ?? (SMB_ROOT . '/storage/logs');
        if (!is_dir($dir) || !is_readable($dir)) {
            return null;
        }
        $count = 0;
        $top = [];
        foreach (array_unique([gmdate('Y-m-d', $since), gmdate('Y-m-d', $now)]) as $day) {
            $file = $dir . '/app-' . $day . '.log';
            if (!is_file($file) || !is_readable($file) || ($fh = @fopen($file, 'rb')) === false) {
                continue;
            }
            $size = (int) filesize($file);
            $max = 2_000_000;                       // never read more than the last 2 MB of a day's log
            if ($size > $max) {
                fseek($fh, $size - $max);
                fgets($fh);                          // drop the partial first line
            }
            while (($line = fgets($fh)) !== false) {
                if (!str_contains($line, '"level":"error"')) {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row) || ($row['level'] ?? '') !== 'error') {
                    continue;
                }
                $ts = strtotime((string) ($row['ts'] ?? ''));
                if ($ts === false || $ts < $since || $ts > $now + 60) {
                    continue;
                }
                $count++;
                $message = mb_substr(Jobs::clean((string) ($row['message'] ?? 'error')), 0, 80);
                $top[$message] = ($top[$message] ?? 0) + 1;
            }
            fclose($fh);
        }
        arsort($top);
        return ['count' => $count, 'top' => array_slice($top, 0, 3, true)];
    }

    // ── 11. financial discrepancies ──

    private static function finance(int $now, array $t): array
    {
        $issues = [];
        foreach (FinanceReport::reconciliation()['issues'] as $i) {
            $issues[$i['key']] = $i;
        }
        $amountMismatch = (int) Db::val('SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.paid_at IS NOT NULL AND p.paid_amount_paise IS NOT NULL AND p.paid_amount_paise <> o.amount_paise');
        $unverified = (int) ($issues['revenue_unverified']['count'] ?? 0);
        $orphan = (int) ($issues['payment_without_order_paid']['count'] ?? 0);
        $refunds = (int) ($issues['refund_mismatch']['count'] ?? 0);
        $values = ['revenue_without_verified_payment' => $unverified, 'verified_payment_order_not_paid' => $orphan, 'paid_amount_differs' => $amountMismatch, 'refund_mismatch' => $refunds];
        $parts = [];
        if ($unverified > 0) {
            $parts[] = self::plural($unverified, 'paid order') . ' without a verified payment';
        }
        if ($orphan > 0) {
            $parts[] = self::plural($orphan, 'verified payment') . ' whose order is not marked paid';
        }
        if ($amountMismatch > 0) {
            $parts[] = self::plural($amountMismatch, 'payment') . ' whose verified amount differs from the order';
        }
        if ($refunds > 0) {
            $parts[] = self::plural($refunds, 'order') . ' whose refund status and refunded amount disagree';
        }
        if (!$parts) {
            return self::healthy('Orders, verified payments and refunds agree.', $values);
        }
        $sample = array_values(array_unique(array_merge(...array_map(
            static fn(string $k): array => array_map(static fn(array $s): string => $s['public_id'], $issues[$k]['sample'] ?? []),
            ['revenue_unverified', 'refund_mismatch']
        ))));
        return self::alerting(ucfirst(implode('; ', $parts)) . '.', [[
            'suffix' => '', 'severity' => ($unverified + $orphan + $amountMismatch) > 0 ? 'critical' : 'warning', 'title' => 'Money records disagree',
            'text' => ucfirst(implode('; ', $parts)) . '.' . ($sample ? ' For example ' . implode(', ', array_slice($sample, 0, 3)) . '.' : '') . ' Nothing was changed automatically. See Payment checks and Finance → Reconciliation before trusting the revenue.',
            'details' => $values + ['sample' => array_slice($sample, 0, 5)],
        ]], $values);
    }

    // ── 12. provider balance (optional) ──

    private static function providerBalance(int $now, array $t, bool $live): array
    {
        $p = SmmProviders::defaultRow();
        if ($p === null) {
            return self::noVerdict('unavailable', 'No SMM provider is connected, so there is no balance to read.');
        }
        $fresh = $p['last_tested_at'] !== null && $p['last_balance'] !== null && $now - (int) Clock::fromSql((string) $p['last_tested_at']) < 3600;
        if (!$fresh) {
            if (!$live) {
                return self::noVerdict('unavailable', 'Dry run: the provider is not contacted, and its last reading is older than an hour.');
            }
            $r = SmmProviders::checkBalance($p, self::$balanceFetcher);
            if (!$r['ok']) {
                return self::noVerdict('unavailable', 'The provider did not return a balance (' . Jobs::clean($r['message']) . ').');
            }
            $p['last_balance'] = $r['balance'];
        }
        $text = (string) $p['last_balance'];
        $micros = preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*([A-Za-z]{0,6})\s*$/', $text, $m) === 1 ? Money::parseDecimalMicros(ltrim($m[1], '-')) : null;
        if ($micros === null) {
            return self::noVerdict('unavailable', 'The provider’s answer did not contain a usable balance.');
        }
        $micros = str_starts_with(trim($text), '-') ? -$micros : $micros;
        $currency = strtoupper($m[2] ?? '') ?: 'INR';
        $values = ['balance' => $text, 'min' => $t['balance_min']];
        if ($t['balance_min'] <= 0) {
            return self::healthy("The provider reports a balance of {$text}. No minimum is set, so it is only recorded.", $values);
        }
        $min = $t['balance_min'] * 1_000_000;
        if ($micros >= $min) {
            return self::healthy("The provider reports a balance of {$text}, above the minimum of {$t['balance_min']} {$currency}.", $values);
        }
        return self::alerting("The provider reports a balance of {$text}, below the minimum of {$t['balance_min']} {$currency}.", [[
            'suffix' => '', 'entity_type' => 'provider', 'entity_id' => (string) $p['id'], 'severity' => ($micros <= 0 || $micros * 2 < $min) ? 'critical' : 'warning', 'title' => 'SMM provider balance is low',
            'text' => "The provider reports a balance of {$text}, below your minimum of {$t['balance_min']} {$currency}. Orders can be rejected when the balance runs out. Top up at the provider; this is the balance it reported "
                . 'at ' . Clock::iso($p['last_tested_at']) . '.',
            'details' => $values,
        ]], $values);
    }

    // ── 13. sales target pacing ──

    /** The current sales target's own projected-finish pacing (App\Services\SalesTarget — never a separate computation), while it is running or just ended. */
    private static function salesTargetPacing(int $now, array $t): array
    {
        $p = SalesTarget::progress();
        if (!$p['has_target']) {
            return self::noVerdict('insufficient', 'No sales target is set.');
        }
        $period = $p['target']['period_start'] . ' to ' . $p['target']['period_end'];
        $amount = Money::inr($p['target']['target_paise'], true);
        if ($p['status'] === 'not_started') {
            return self::noVerdict('insufficient', "The sales target of {$amount} for {$period} has not started yet.", ['status' => $p['status']]);
        }
        $pct = $p['status'] === 'ended' ? $p['pct_of_target_tenths'] : $p['projected_pct_tenths'];
        $label = $p['status'] === 'ended' ? 'result' : 'projected finish';
        $values = ['status' => $p['status'], 'target_paise' => $p['target']['target_paise'], 'actual_paise' => $p['actual_paise'], 'pct_of_target_tenths' => $pct, 'pace_label' => $p['pace_label']];
        if ($p['pace_label'] !== 'behind') {
            return self::healthy("The sales target of {$amount} for {$period} is {$p['pace_label']} ({$label} " . Money::tenthsToString($pct) . '% of target).', $values);
        }
        $summary = ($p['status'] === 'ended' ? 'The sales target' : 'The current sales target') . " of {$amount} for {$period} is behind pace ({$label} " . Money::tenthsToString($pct) . '% of target, actual so far ' . Money::inr($p['actual_paise'], true) . ').';
        return self::alerting($summary, [[
            'suffix' => '', 'severity' => $pct < $t['target_critical_pct'] * 10 ? 'critical' : 'warning', 'title' => 'Sales target is behind pace',
            'text' => $summary . ($p['status'] === 'in_progress' ? ' ' . Money::inr($p['required_daily_pace_paise'] ?? 0, true) . ' a day is needed for the rest of the period to reach it — see Admin → Advertising.' : ' The period has ended — see Admin → Advertising.'),
            'details' => $values,
        ]], $values);
    }

    // ── 14. advertising budget ──

    /** Today's and this month's actual ad spend (App\Services\AdBudget — never a separate computation) against the configured budget cap. */
    private static function adBudget(int $now, array $t): array
    {
        $s = AdBudget::spendSoFar();
        if ($s['daily_budget_paise'] === null && $s['monthly_budget_paise'] === null) {
            return self::noVerdict('insufficient', 'No advertising budget cap is set.');
        }
        $values = ['daily_budget_paise' => $s['daily_budget_paise'], 'monthly_budget_paise' => $s['monthly_budget_paise'], 'today_paise' => $s['today_paise'], 'month_paise' => $s['month_paise']];
        $parts = [];
        $ok = [];
        $critical = false;
        if ($s['daily_budget_paise'] !== null) {
            if ($s['today_paise'] > $s['daily_budget_paise']) {
                $pct = Money::pctTenths($s['today_paise'], $s['daily_budget_paise']);
                $parts[] = "today's spend is " . Money::inr($s['today_paise'], true) . ', over the ' . Money::inr($s['daily_budget_paise'], true) . ' daily budget (' . Money::tenthsToString($pct) . '% of it)';
                $critical = $critical || $pct >= $t['budget_critical_pct'] * 10;
            } else {
                $ok[] = "today's spend is " . Money::inr($s['today_paise'], true) . ', within the ' . Money::inr($s['daily_budget_paise'], true) . ' daily budget';
            }
        }
        if ($s['monthly_budget_paise'] !== null) {
            if ($s['month_paise'] > $s['monthly_budget_paise']) {
                $pct = Money::pctTenths($s['month_paise'], $s['monthly_budget_paise']);
                $parts[] = "this month's spend is " . Money::inr($s['month_paise'], true) . ', over the ' . Money::inr($s['monthly_budget_paise'], true) . ' monthly budget (' . Money::tenthsToString($pct) . '% of it)';
                $critical = $critical || $pct >= $t['budget_critical_pct'] * 10;
            } else {
                $ok[] = "this month's spend is " . Money::inr($s['month_paise'], true) . ', within the ' . Money::inr($s['monthly_budget_paise'], true) . ' monthly budget';
            }
        }
        if (!$parts) {
            return self::healthy(ucfirst(implode('; ', $ok)) . '.', $values);
        }
        $summary = ucfirst(implode('; ', array_merge($parts, $ok))) . '.';
        return self::alerting($summary, [[
            'suffix' => '', 'severity' => $critical ? 'critical' : 'warning', 'title' => 'Advertising spend is over budget',
            'text' => $summary . ' The budget is informational only — nothing was blocked. See Admin → Advertising.',
            'details' => $values,
        ]], $values);
    }

    // ═══════════════════════════ helpers ════════════════════════════════════

    /**
     * Make a details array safe to store and show: scalars and nested arrays only, strings cleaned of paths and long opaque tokens.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function safe(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $depth < 3 ? self::safe($v, $depth + 1) : [];
            } elseif (is_string($v)) {
                $out[$k] = Jobs::clean($v);
            } elseif (is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
