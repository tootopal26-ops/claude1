<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Money;
use App\Support\PricingRules;
use App\Support\Tz;

/**
 * Adaptive pricing engine (master prompt, Adaptive Pricing & AI Sales Optimization Engine).
 *
 * THE SOURCE OF TRUTH FOR A PRICE IS STILL `services.price_paise` — nothing here changes it except `applyWinner()`, an explicit,
 * audited, owner-approved action after an experiment has concluded with a winning candidate. An experiment only ever shows a
 * DIFFERENT price to its own treatment cohort, for as long as it runs; every visitor who is not part of a live experiment, and
 * every visitor once an experiment ends, sees the service's normal price.
 *
 * SAFETY BY CONSTRUCTION
 *   - A new experiment always starts in `mode = dry_run`: both its cohorts are shown the SAME (current default) price. This is a
 *     real randomised A/A test against real traffic — it validates that the assignment/measurement pipeline is unbiased before any
 *     customer is ever shown a different price. Only `goLive()` (permission `pricing.approve`, owner only) switches a cohort to the
 *     candidate price, and it never changes the price already shown to a visitor who was assigned before the switch.
 *   - Only explicitly enabled (`services.adaptive_enabled`), MANUAL-mode services with a configured fulfilment cost are eligible —
 *     a missing cost is never treated as free, and AUTO (API) prices are never touched.
 *   - A global pause (`pricing.global_pause`) or a per-service pause (`services.pricing_paused`) stops ALL new assignments at once;
 *     neither one rewrites a price an order already carries.
 *   - Rollback triggers (profit floor breach, conversion decline, payment/order malfunction, missing or inconsistent attribution,
 *     a cost increase, the experiment outliving its maximum duration, an unexpected default-price change) are evaluated on every
 *     run of the `pricing` cron job and act immediately and automatically — no AI, no human click needed to stop a losing test.
 *   - Every assignment is a keyed hash of the visitor's existing first-party cookie (never an IP, never a name) and is stable for
 *     its configured lifetime, so the same visitor keeps the same price for as long as the experiment runs.
 *
 * AI never decides a price or a rollback — see App\Services\PricingAi for the advisory-only explanation layer.
 */
final class Pricing
{
    public const COHORTS = ['control', 'treatment'];
    public const ACTIVE_STATUSES = ['proposed', 'running', 'paused'];
    public const ENDED_STATUSES = ['concluded', 'rolled_back', 'cancelled'];

    /** @var array<int,array<string,mixed>>|null service_id => running experiment row, request-scoped cache */
    private static ?array $activeCache = null;

    public static function resetCache(): void
    {
        self::$activeCache = null;
    }

    // ═══════════════════════════ settings ═══════════════════════════════════

    /** @return array<string,mixed> */
    public static function settings(): array
    {
        $bps = static fn(string $k, int $d): int => max(0, min(10000, (int) Settings::get($k, $d)));
        return [
            'global_pause' => (bool) Settings::get('pricing.global_pause', false),
            'cooldown_days' => max(0, min(365, (int) Settings::get('pricing.cooldown_days', 14))),
            'assignment_ttl_days' => max(1, min(365, (int) Settings::get('pricing.assignment_ttl_days', 30))),
            'default_treatment_share_bps' => $bps('pricing.default_treatment_share_bps', 5000),
            'default_min_sample' => max(10, min(1_000_000, (int) Settings::get('pricing.default_min_sample', 200))),
            'default_max_duration_days' => max(1, min(365, (int) Settings::get('pricing.default_max_duration_days', 21))),
            'min_price_paise' => max(100, min(10_000_000, (int) Settings::get('pricing.min_price_paise', PricingRules::DEFAULT_MIN_PAISE))),
            'rollback_conversion_tolerance_bps' => $bps('pricing.rollback_conversion_tolerance_bps', 5000),
            'rollback_failure_gap_bps' => $bps('pricing.rollback_failure_gap_bps', 2000),
            'decision_margin_bps' => $bps('pricing.decision_margin_bps', 500),
            'cost_increase_tolerance_bps' => $bps('pricing.cost_increase_tolerance_bps', 0),
            'max_price_change_bps' => $bps('pricing.max_price_change_bps', 5000),
        ];
    }

    /**
     * @param array<string,mixed> $in
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed>
     */
    public static function saveSettings(array $in, ?Request $req, array $admin): array
    {
        $cur = self::settings();
        $errors = [];
        $set = [];
        $boolF = static function (string $f) use ($in, &$set, &$errors): void {
            if (array_key_exists($f, $in)) {
                if (!is_bool($in[$f])) {
                    $errors[$f] = 'Choose on or off.';
                } else {
                    $set['pricing.' . $f] = $in[$f];
                }
            }
        };
        $intF = static function (string $f, int $min, int $max, string $msg) use ($in, &$set, &$errors): void {
            if (array_key_exists($f, $in)) {
                $v = $in[$f];
                if (!is_int($v) && !(is_string($v) && preg_match('/^\d{1,9}$/D', $v))) {
                    $errors[$f] = $msg;
                } elseif ((int) $v < $min || (int) $v > $max) {
                    $errors[$f] = $msg;
                } else {
                    $set['pricing.' . $f] = (int) $v;
                }
            }
        };
        $boolF('global_pause');
        $intF('cooldown_days', 0, 365, 'Enter 0 to 365 days.');
        $intF('assignment_ttl_days', 1, 365, 'Enter 1 to 365 days.');
        $intF('default_treatment_share_bps', 100, 9900, 'Enter 1% to 99% (as basis points, 100–9900).');
        $intF('default_min_sample', 10, 1_000_000, 'Enter at least 10.');
        $intF('default_max_duration_days', 1, 365, 'Enter 1 to 365 days.');
        if (array_key_exists('min_price_paise', $in)) {
            try {
                $set['pricing.min_price_paise'] = Money::parseRupees(is_scalar($in['min_price_paise']) ? $in['min_price_paise'] : null);
            } catch (\InvalidArgumentException) {
                $errors['min_price_paise'] = 'Enter the minimum price in rupees.';
            }
        }
        $intF('rollback_conversion_tolerance_bps', 0, 10000, 'Enter 0 to 10000 basis points.');
        $intF('rollback_failure_gap_bps', 0, 10000, 'Enter 0 to 10000 basis points.');
        $intF('decision_margin_bps', 0, 10000, 'Enter 0 to 10000 basis points.');
        $intF('cost_increase_tolerance_bps', 0, 10000, 'Enter 0 to 10000 basis points.');
        $intF('max_price_change_bps', 100, 10000, 'Enter 100 to 10000 basis points.');
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        if ($set) {
            Settings::setMany($set, $admin['id']);
            Audit::log('pricing.settings_changed', 'settings', 'pricing', ['from' => $cur, 'to' => self::settings()], $req, $admin);
        }
        self::resetCache();
        return self::settings();
    }

    public static function setGlobalPause(bool $paused, ?Request $req, array $admin): array
    {
        Settings::set('pricing.global_pause', $paused, $admin['id']);
        Audit::log($paused ? 'pricing.global_paused' : 'pricing.global_resumed', 'settings', 'pricing', [], $req, $admin);
        self::resetCache();
        return self::settings();
    }

    // ═══════════════════════════ eligible services ══════════════════════════

    /** @return list<array<string,mixed>> every MANUAL, non-deleted service, with its adaptive-pricing configuration and eligibility */
    public static function eligibleServices(): array
    {
        $rows = Db::all(
            "SELECT s.*, p.slug AS platform_slug, p.name AS platform_name FROM services s JOIN platforms p ON p.id = s.platform_id "
            . "WHERE s.mode = 'MANUAL' AND s.deleted_at IS NULL ORDER BY p.sort_order, p.id, s.sort_order, s.id"
        );
        return array_map(static fn(array $r): array => self::serviceView($r), $rows);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function serviceView(array $r): array
    {
        $id = (int) $r['id'];
        $elig = self::eligibility($r);
        $active = Db::one("SELECT id, status, mode, candidate_paise FROM pricing_experiments WHERE service_id = ? AND status IN ('proposed','running','paused') ORDER BY id DESC LIMIT 1", [$id]);
        $cooldown = Db::val("SELECT cooldown_until FROM pricing_experiments WHERE service_id = ? AND cooldown_until IS NOT NULL ORDER BY id DESC LIMIT 1", [$id]);
        return [
            'id' => $id, 'title' => (string) $r['title'], 'platform' => $r['platform_slug'], 'platform_name' => $r['platform_name'],
            'code' => (string) $r['code'], 'status' => (string) $r['status'],
            'price_paise' => (int) $r['price_paise'], 'fulfil_cost_paise' => $r['fulfil_cost_paise'] !== null ? (int) $r['fulfil_cost_paise'] : null,
            'adaptive_enabled' => (bool) $r['adaptive_enabled'], 'adaptive_min_paise' => $r['adaptive_min_paise'] !== null ? (int) $r['adaptive_min_paise'] : null,
            'adaptive_max_paise' => $r['adaptive_max_paise'] !== null ? (int) $r['adaptive_max_paise'] : null, 'pricing_paused' => (bool) $r['pricing_paused'],
            'eligible' => $elig['eligible'], 'reasons' => $elig['reasons'],
            'active_experiment' => $active !== null ? ['id' => (int) $active['id'], 'status' => $active['status'], 'mode' => $active['mode'], 'candidate_paise' => (int) $active['candidate_paise']] : null,
            'cooldown_until' => $cooldown !== null ? Clock::iso((string) $cooldown) : null,
        ];
    }

    /** @param array<string,mixed> $svc services row @return array{eligible:bool,reasons:list<string>} */
    private static function eligibility(array $svc): array
    {
        $reasons = [];
        if ((string) $svc['mode'] !== 'MANUAL') {
            $reasons[] = 'Only manual-mode services are eligible by default — API/AUTO prices are never adjusted automatically.';
        }
        if (!(bool) $svc['adaptive_enabled']) {
            $reasons[] = 'Adaptive pricing is not switched on for this service.';
        }
        if ((bool) $svc['pricing_paused']) {
            $reasons[] = 'This service’s pricing is paused.';
        }
        if ($svc['fulfil_cost_paise'] === null) {
            $reasons[] = 'No fulfilment cost is configured — a missing cost is never treated as free, so this service stays ineligible until it is set.';
        }
        if ((bool) Settings::get('pricing.global_pause', false)) {
            $reasons[] = 'Pricing experiments are globally paused.';
        }
        $id = (int) ($svc['id'] ?? 0);
        if ($id > 0 && Db::val("SELECT COUNT(*) FROM pricing_experiments WHERE service_id = ? AND status IN ('proposed','running','paused')", [$id]) > 0) {
            $reasons[] = 'An experiment is already in progress for this service.';
        }
        if ($id > 0) {
            $cd = Db::val("SELECT cooldown_until FROM pricing_experiments WHERE service_id = ? AND status IN ('concluded','rolled_back') ORDER BY COALESCE(ended_at, rolled_back_at) DESC LIMIT 1", [$id]);
            if ($cd !== null && (int) Clock::fromSql((string) $cd) > Clock::now()) {
                $reasons[] = 'In cooldown until ' . Clock::iso((string) $cd) . ' after the last experiment on this service.';
            }
        }
        return ['eligible' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * @param array<string,mixed> $in {adaptive_enabled?, min_price?, max_price?, pricing_paused?}
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed>
     */
    public static function setServiceAdaptive(int $serviceId, array $in, ?Request $req, array $admin): array
    {
        $svc = Db::one('SELECT * FROM services WHERE id = ? AND deleted_at IS NULL', [$serviceId]) ?? throw new HttpException(404, 'Service not found.');
        if ((string) $svc['mode'] !== 'MANUAL' && (($in['adaptive_enabled'] ?? false) === true)) {
            throw new HttpException(422, 'Only manual-mode services can have adaptive pricing enabled.', 'invalid', ['fields' => ['adaptive_enabled' => 'This is an AUTO (API) service.']]);
        }
        $errors = [];
        $enabled = array_key_exists('adaptive_enabled', $in) ? (bool) $in['adaptive_enabled'] : (bool) $svc['adaptive_enabled'];
        $paused = array_key_exists('pricing_paused', $in) ? (bool) $in['pricing_paused'] : (bool) $svc['pricing_paused'];
        $min = $svc['adaptive_min_paise'] !== null ? (int) $svc['adaptive_min_paise'] : null;
        $max = $svc['adaptive_max_paise'] !== null ? (int) $svc['adaptive_max_paise'] : null;
        $money = static function (string $field, mixed $raw, ?int $fallback) use (&$errors): ?int {
            if ($raw === null || $raw === '') {
                return null;
            }
            try {
                return Money::parseRupees(is_scalar($raw) ? $raw : null);
            } catch (\InvalidArgumentException) {
                $errors[$field] = 'Enter an amount in rupees, or leave it empty.';
                return $fallback;
            }
        };
        if (array_key_exists('min_price', $in)) {
            $min = $money('min_price', $in['min_price'], $min);
        }
        if (array_key_exists('max_price', $in)) {
            $max = $money('max_price', $in['max_price'], $max);
        }
        if ($min !== null && $max !== null && $min > $max) {
            $errors['max_price'] = 'The maximum must not be below the minimum.';
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        Db::update('services', ['adaptive_enabled' => $enabled ? 1 : 0, 'adaptive_min_paise' => $min, 'adaptive_max_paise' => $max, 'pricing_paused' => $paused ? 1 : 0, 'updated_at' => Clock::sql()], 'id = ?', [$serviceId]);
        Audit::log('pricing.service_settings_changed', 'service', $serviceId, ['adaptive_enabled' => $enabled, 'min' => $min, 'max' => $max, 'paused' => $paused], $req, $admin);
        self::resetCache();
        return self::serviceView(Db::one('SELECT s.*, p.slug AS platform_slug, p.name AS platform_name FROM services s JOIN platforms p ON p.id = s.platform_id WHERE s.id = ?', [$serviceId]));
    }

    // ═══════════════════════════ simulator (real data, no DB writes) ════════

    /**
     * Compare candidate prices against the service's real recent order history and real configured costs — a volume-held-constant
     * projection, never a demand forecast (no per-service visit/conversion history exists until an experiment actually runs).
     *
     * @param list<mixed> $candidatesInput rupee strings
     * @return array<string,mixed>
     */
    public static function simulate(int $serviceId, array $candidatesInput): array
    {
        $svc = Db::one(
            "SELECT s.*, p.slug AS platform_slug FROM services s JOIN platforms p ON p.id = s.platform_id WHERE s.id = ? AND s.deleted_at IS NULL",
            [$serviceId]
        ) ?? throw new HttpException(404, 'Service not found.');
        $elig = self::eligibility($svc);
        $minPaise = $svc['adaptive_min_paise'] !== null ? (int) $svc['adaptive_min_paise'] : self::settings()['min_price_paise'];
        $maxPaise = $svc['adaptive_max_paise'] !== null ? (int) $svc['adaptive_max_paise'] : null;

        $fulfil = $svc['fulfil_cost_paise'] !== null ? (int) $svc['fulfil_cost_paise'] : null;
        $estAd = Ads::estimatedPerOrder();
        $current = (int) $svc['price_paise'];

        // real trailing order volume: the last 90 days, or this service's whole history if fewer than 5 paid orders happened in that window
        $from90 = Tz::addDays(Tz::today(), -89);
        $recent = (int) Db::val('SELECT COUNT(*) FROM orders WHERE service_id = ? AND paid_at IS NOT NULL AND paid_day >= ?', [$serviceId, $from90]);
        $windowLabel = 'the last 90 days';
        $volume = $recent;
        if ($recent < 5) {
            $all = (int) Db::val('SELECT COUNT(*) FROM orders WHERE service_id = ? AND paid_at IS NOT NULL', [$serviceId]);
            if ($all > $recent) {
                $volume = $all;
                $windowLabel = 'this service’s entire order history (too few paid orders in the last 90 days)';
            }
        }
        $currentContribution = $current - $fulfil - $estAd['paise'];

        $candidates = [];
        foreach ($candidatesInput as $raw) {
            try {
                $p = Money::parseRupees(is_scalar($raw) ? $raw : null);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $validEnding = PricingRules::validEnding($p, $minPaise);
            $withinBounds = $p >= $minPaise && ($maxPaise === null || $p <= $maxPaise);
            $contribution = $fulfil !== null ? $p - $fulfil - $estAd['paise'] : null;
            $candidates[] = [
                'price_paise' => $p, 'price' => Money::inr($p, true),
                'valid_ending' => $validEnding, 'rejection_reason' => $validEnding ? null : PricingRules::explainRejection($p, $minPaise),
                'within_configured_bounds' => $withinBounds,
                'change_from_current_bps' => $current > 0 ? Money::mulDiv($p - $current, 10000, $current) : null,
                'contribution_per_order_paise' => $contribution,
                'margin_tenths' => $contribution !== null ? Money::pctTenths($contribution, $p) : null,
                'projected_orders_same_volume' => $volume,
                'projected_contribution_paise' => $contribution !== null ? $contribution * $volume : null,
                'projected_contribution_delta_vs_current_paise' => $contribution !== null && $fulfil !== null ? ($contribution - $currentContribution) * $volume : null,
                'activatable' => $validEnding && $withinBounds && $fulfil !== null,
            ];
        }

        return [
            'service' => ['id' => (int) $svc['id'], 'title' => (string) $svc['title'], 'platform' => $svc['platform_slug'], 'price_paise' => $current, 'price' => Money::inr($current, true)],
            'eligible' => $elig['eligible'], 'reasons' => $elig['reasons'],
            'fulfil_cost_paise' => $fulfil, 'est_ad_cost_paise' => $estAd['paise'], 'est_ad_cost_basis' => $estAd['basis'],
            'current_contribution_per_order_paise' => $fulfil !== null ? $currentContribution : null,
            'volume_window' => $windowLabel, 'projected_orders_same_volume' => $volume,
            'min_price_paise' => $minPaise, 'max_price_paise' => $maxPaise,
            'candidates' => $candidates,
            'notes' => [
                'Projections hold order volume constant at the real figure above — they are not a demand forecast. Buying behaviour at a new price is only measured once a real experiment runs.',
                'A missing fulfilment cost is never treated as ₹0: the service stays ineligible for activation until it is configured.',
                'The estimated advertising cost per order is the same figure Services and Finance already use (' . $estAd['basis'] . ').',
            ],
        ];
    }

    // ═══════════════════════════ experiment lifecycle ═══════════════════════

    /** @param array<string,mixed> $in @param array{id:int,username:string,email:string} $admin @return array<string,mixed> */
    public static function propose(int $serviceId, array $in, ?Request $req, array $admin): array
    {
        $svc = Db::one('SELECT * FROM services WHERE id = ? AND deleted_at IS NULL', [$serviceId]) ?? throw new HttpException(404, 'Service not found.');
        $elig = self::eligibility($svc);
        if (!$elig['eligible']) {
            throw new HttpException(422, 'This service is not eligible for a pricing experiment right now.', 'not_eligible', ['fields' => ['service' => implode(' ', $elig['reasons'])]]);
        }
        $s = self::settings();
        $minPaise = $svc['adaptive_min_paise'] !== null ? (int) $svc['adaptive_min_paise'] : $s['min_price_paise'];
        $maxPaise = $svc['adaptive_max_paise'] !== null ? (int) $svc['adaptive_max_paise'] : null;
        $current = (int) $svc['price_paise'];

        $errors = [];
        $candidate = 0;
        try {
            $candidate = Money::parseRupees(is_scalar($in['candidate_price'] ?? null) ? $in['candidate_price'] : null);
        } catch (\InvalidArgumentException) {
            $errors['candidate_price'] = 'Enter the candidate price in rupees.';
        }
        if ($candidate > 0) {
            if (!PricingRules::validEnding($candidate, $minPaise)) {
                $sug = PricingRules::suggestNear($candidate, $minPaise);
                $errors['candidate_price'] = (PricingRules::explainRejection($candidate, $minPaise) ?? 'Not a valid adaptive price.')
                    . ($sug['below'] !== null || $sug['above'] !== null ? ' Try ' . trim((($sug['below'] !== null ? Money::inr($sug['below'], true) : '')) . (($sug['below'] !== null && $sug['above'] !== null) ? ' or ' : '') . (($sug['above'] !== null ? Money::inr($sug['above'], true) : '')), ' or') . '.' : '');
            } elseif ($maxPaise !== null && $candidate > $maxPaise) {
                $errors['candidate_price'] = 'Above this service’s configured maximum of ' . Money::inr($maxPaise, true) . '.';
            } elseif ($candidate === $current) {
                $errors['candidate_price'] = 'The candidate must differ from the current price.';
            } elseif ($current > 0 && Money::mulDiv(abs($candidate - $current), 10000, $current) > $s['max_price_change_bps']) {
                $errors['candidate_price'] = 'That is more than the allowed maximum change (' . Money::tenthsToString((int) round($s['max_price_change_bps'] / 10)) . '%) from the current price in one experiment.';
            }
        }
        $treatmentShare = array_key_exists('treatment_share_pct', $in) && is_numeric($in['treatment_share_pct'])
            ? (int) round((float) $in['treatment_share_pct'] * 100) : $s['default_treatment_share_bps'];
        if ($treatmentShare < 100 || $treatmentShare > 9900) {
            $errors['treatment_share_pct'] = 'Choose a treatment share between 1% and 99%.';
        }
        $minSample = array_key_exists('min_sample', $in) && is_numeric($in['min_sample']) ? (int) $in['min_sample'] : $s['default_min_sample'];
        if ($minSample < 10 || $minSample > 1_000_000) {
            $errors['min_sample'] = 'Enter a minimum sample size of at least 10.';
        }
        $maxDuration = array_key_exists('max_duration_days', $in) && is_numeric($in['max_duration_days']) ? (int) $in['max_duration_days'] : $s['default_max_duration_days'];
        if ($maxDuration < 1 || $maxDuration > 365) {
            $errors['max_duration_days'] = 'Enter a duration between 1 and 365 days.';
        }
        $profitFloor = 0;
        if (array_key_exists('profit_floor', $in) && $in['profit_floor'] !== null && $in['profit_floor'] !== '') {
            try {
                $profitFloor = Money::parseRupees(is_scalar($in['profit_floor']) ? $in['profit_floor'] : null);
            } catch (\InvalidArgumentException) {
                $errors['profit_floor'] = 'Enter the minimum acceptable contribution profit in rupees.';
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }

        $now = Clock::sql();
        $costSnapshot = ['smm_cost_paise' => (int) $svc['smm_cost_paise'], 'fulfil_cost_paise' => $svc['fulfil_cost_paise'] !== null ? (int) $svc['fulfil_cost_paise'] : null, 'at' => Clock::iso($now)];

        $id = Db::tx(static function () use ($serviceId, $current, $candidate, $treatmentShare, $minSample, $maxDuration, $profitFloor, $admin, $now, $costSnapshot): int {
            $expId = Db::insert('pricing_experiments', [
                'service_id' => $serviceId, 'status' => 'proposed', 'mode' => 'dry_run', 'control_paise' => $current, 'candidate_paise' => $candidate,
                'treatment_share_bps' => $treatmentShare, 'min_sample_per_cohort' => $minSample, 'max_duration_days' => $maxDuration,
                'profit_floor_paise' => $profitFloor, 'metrics_json' => json_encode(['cost_snapshot' => $costSnapshot], JSON_UNESCAPED_UNICODE),
                'created_by' => $admin['id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $controlVersionId = Db::insert('service_price_versions', ['service_id' => $serviceId, 'price_paise' => $current, 'kind' => 'control', 'experiment_id' => $expId, 'note' => 'Control price snapshot for experiment #' . $expId, 'created_by' => $admin['id'], 'created_at' => $now]);
            $candidateVersionId = Db::insert('service_price_versions', ['service_id' => $serviceId, 'price_paise' => $candidate, 'kind' => 'candidate', 'experiment_id' => $expId, 'note' => 'Candidate price for experiment #' . $expId, 'created_by' => $admin['id'], 'created_at' => $now]);
            Db::update('pricing_experiments', ['control_version_id' => $controlVersionId, 'candidate_version_id' => $candidateVersionId], 'id = ?', [$expId]);
            return $expId;
        });
        Audit::log('pricing.experiment_proposed', 'pricing_experiment', $id, ['service_id' => $serviceId, 'control' => Money::plain($current), 'candidate' => Money::plain($candidate)], $req, $admin);
        self::log($id, $serviceId, 'proposed', true, 'Experiment proposed: ' . Money::inr($current, true) . ' (control) vs ' . Money::inr($candidate, true) . ' (candidate).', null, 'admin');
        return self::experiment($id);
    }

    /** @param array{id:int,username:string,email:string} $admin */
    public static function start(int $id, string $mode, ?Request $req, array $admin): array
    {
        if (!in_array($mode, ['dry_run', 'live'], true)) {
            throw new HttpException(422, 'Choose dry-run or live.', 'invalid', ['fields' => ['mode' => 'Choose dry-run or live.']]);
        }
        $exp = self::requireExperiment($id);
        if ($exp['status'] !== 'proposed') {
            throw new HttpException(409, 'Only a proposed experiment can be started.', 'conflict');
        }
        $now = Clock::now();
        $ends = $now + (int) $exp['max_duration_days'] * 86400;
        $data = ['status' => 'running', 'mode' => $mode, 'started_at' => Clock::sql($now), 'ends_at' => Clock::sql($ends), 'updated_at' => Clock::sql($now)];
        if ($mode === 'live') {
            $data['approved_by'] = $admin['id'];
            $data['approved_at'] = Clock::sql($now);
        }
        Db::update('pricing_experiments', $data, 'id = ?', [$id]);
        Audit::log('pricing.experiment_started', 'pricing_experiment', $id, ['mode' => $mode], $req, $admin);
        self::log($id, (int) $exp['service_id'], 'started', $mode === 'dry_run', $mode === 'live' ? 'Experiment went live: the treatment cohort now sees the candidate price.' : 'Experiment started as a dry run (A/A test): both cohorts see the same price while the pipeline is validated.', null, 'admin');
        self::resetCache();
        return self::experiment($id);
    }

    /** @param array{id:int,username:string,email:string} $admin */
    public static function goLive(int $id, ?Request $req, array $admin): array
    {
        $exp = self::requireExperiment($id);
        if ($exp['status'] !== 'running' || $exp['mode'] !== 'dry_run') {
            throw new HttpException(409, 'Only a running dry-run experiment can be switched to live.', 'conflict');
        }
        Db::update('pricing_experiments', ['mode' => 'live', 'approved_by' => $admin['id'], 'approved_at' => Clock::sql(), 'updated_at' => Clock::sql()], 'id = ?', [$id]);
        Audit::log('pricing.experiment_live', 'pricing_experiment', $id, [], $req, $admin);
        self::log($id, (int) $exp['service_id'], 'live', false, 'Approved and switched to live: new visitor assignments now see the candidate price.', null, 'admin');
        self::resetCache();
        return self::experiment($id);
    }

    /** @param array{id:int,username:string,email:string} $admin */
    public static function pause(int $id, string $reason, ?Request $req, array $admin): array
    {
        $exp = self::requireExperiment($id);
        if ($exp['status'] !== 'running') {
            throw new HttpException(409, 'Only a running experiment can be paused.', 'conflict');
        }
        Db::update('pricing_experiments', ['status' => 'paused', 'updated_at' => Clock::sql()], 'id = ?', [$id]);
        Audit::log('pricing.experiment_paused', 'pricing_experiment', $id, ['reason' => $reason], $req, $admin);
        self::log($id, (int) $exp['service_id'], 'paused', $exp['mode'] === 'dry_run', $reason !== '' ? $reason : 'Paused by an admin.', null, 'admin');
        self::resetCache();
        return self::experiment($id);
    }

    /** @param array{id:int,username:string,email:string} $admin */
    public static function resume(int $id, ?Request $req, array $admin): array
    {
        $exp = self::requireExperiment($id);
        if ($exp['status'] !== 'paused') {
            throw new HttpException(409, 'Only a paused experiment can be resumed.', 'conflict');
        }
        $now = Clock::now();
        $data = ['status' => 'running', 'updated_at' => Clock::sql($now)];
        if ((int) Clock::fromSql((string) $exp['ends_at']) <= $now) {
            $data['ends_at'] = Clock::sql($now + 86400); // one more day so a long pause does not instantly bounce to "duration exceeded"
        }
        Db::update('pricing_experiments', $data, 'id = ?', [$id]);
        Audit::log('pricing.experiment_resumed', 'pricing_experiment', $id, [], $req, $admin);
        self::log($id, (int) $exp['service_id'], 'resumed', $exp['mode'] === 'dry_run', 'Resumed by an admin.', null, 'admin');
        self::resetCache();
        return self::experiment($id);
    }

    /** @param array{id:int,username:string,email:string}|null $admin null = an automatic (system) rollback */
    public static function rollback(int $id, string $reason, ?Request $req, ?array $admin): array
    {
        $exp = self::requireExperiment($id);
        if (!in_array($exp['status'], ['running', 'paused'], true)) {
            throw new HttpException(409, 'Only a running or paused experiment can be rolled back.', 'conflict');
        }
        self::applyRollback($exp, $reason, $admin !== null ? 'admin' : 'system');
        Audit::log('pricing.experiment_rolled_back', 'pricing_experiment', $id, ['reason' => $reason], $req, $admin);
        return self::experiment($id);
    }

    /** @param array<string,mixed> $exp */
    private static function applyRollback(array $exp, string $reason, string $actor): void
    {
        $id = (int) $exp['id'];
        $now = Clock::sql();
        $cooldownDays = self::settings()['cooldown_days'];
        // if a winning candidate had already been activated on this experiment, put the default price back exactly as it was
        $promoted = Db::one("SELECT * FROM price_audit_logs WHERE experiment_id = ? AND actor_type = 'experiment' ORDER BY id DESC LIMIT 1", [$id]);
        if ($promoted !== null) {
            $svc = Db::one('SELECT price_paise FROM services WHERE id = ?', [$exp['service_id']]);
            if ($svc !== null && (int) $svc['price_paise'] === (int) $promoted['new_price_paise']) {
                Db::update('services', ['price_paise' => (int) $promoted['old_price_paise'], 'updated_at' => $now], 'id = ?', [$exp['service_id']]);
                Db::insert('price_audit_logs', [
                    'service_id' => $exp['service_id'], 'old_price_paise' => (int) $promoted['new_price_paise'], 'new_price_paise' => (int) $promoted['old_price_paise'],
                    'reason' => 'Rolled back: ' . mb_substr($reason, 0, 200), 'actor_type' => 'rollback', 'actor_id' => null, 'experiment_id' => $id, 'created_at' => $now,
                ]);
            }
        }
        Db::update('pricing_experiments', [
            'status' => 'rolled_back', 'decision' => 'rolled_back', 'decision_reason' => mb_substr($reason, 0, 500),
            'rolled_back_at' => $now, 'ended_at' => $now, 'rollback_reason' => mb_substr($reason, 0, 255),
            'cooldown_until' => Clock::sql(Clock::now() + $cooldownDays * 86400), 'updated_at' => $now,
        ], 'id = ?', [$id]);
        self::log($id, (int) $exp['service_id'], 'rolled_back', false, $reason, null, $actor);
        Alerts::raise(
            'pricing.rollback', $actor === 'system' ? 'critical' : 'info',
            'Pricing experiment #' . $id . ' rolled back',
            $reason,
            ['entity_type' => 'pricing_experiment', 'entity_id' => $id, 'dedupe_key' => 'pricing.rollback:' . $id]
        );
        self::resetCache();
    }

    /** @param array{id:int,username:string,email:string} $admin */
    public static function applyWinner(int $id, ?Request $req, array $admin): array
    {
        $exp = self::requireExperiment($id);
        if ($exp['status'] !== 'concluded' || $exp['decision'] !== 'winner') {
            throw new HttpException(409, 'Only a concluded experiment with a winning decision can be activated.', 'conflict');
        }
        if (Db::val("SELECT COUNT(*) FROM price_audit_logs WHERE experiment_id = ? AND actor_type = 'experiment'", [$id]) > 0) {
            throw new HttpException(409, 'This experiment’s candidate price was already activated.', 'conflict');
        }
        $svc = Db::one('SELECT * FROM services WHERE id = ?', [$exp['service_id']]) ?? throw new HttpException(404, 'Service not found.');
        $old = (int) $svc['price_paise'];
        $candidate = (int) $exp['candidate_paise'];
        $now = Clock::sql();
        Db::tx(static function () use ($exp, $svc, $old, $candidate, $now, $admin, $id): void {
            Db::update('services', ['price_paise' => $candidate, 'updated_at' => $now], 'id = ?', [$exp['service_id']]);
            Db::insert('service_pricing', ['service_id' => $exp['service_id'], 'price_paise' => $candidate, 'cost_paise' => (int) $svc['smm_cost_paise'], 'changed_by' => $admin['id'], 'note' => 'Activated from pricing experiment #' . $id, 'created_at' => $now]);
            Db::insert('price_audit_logs', ['service_id' => $exp['service_id'], 'old_price_paise' => $old, 'new_price_paise' => $candidate, 'reason' => 'Candidate price activated after a winning pricing experiment', 'actor_type' => 'experiment', 'actor_id' => $admin['id'], 'experiment_id' => $id, 'price_version_id' => $exp['candidate_version_id'], 'created_at' => $now]);
            Db::insert('service_price_versions', ['service_id' => $exp['service_id'], 'price_paise' => $candidate, 'kind' => 'default', 'experiment_id' => $id, 'note' => 'Became the default price', 'created_by' => $admin['id'], 'created_at' => $now]);
        });
        Audit::log('pricing.experiment_applied', 'service', (int) $exp['service_id'], ['experiment_id' => $id, 'from' => Money::plain($old), 'to' => Money::plain($candidate)], $req, $admin);
        self::log($id, (int) $exp['service_id'], 'applied', false, 'Winning candidate price activated as the new default.', null, 'admin');
        self::resetCache();
        return self::experiment($id);
    }

    /** @param array{id:int,username:string,email:string} $admin */
    public static function cancel(int $id, string $reason, ?Request $req, array $admin): array
    {
        $exp = self::requireExperiment($id);
        if ($exp['status'] !== 'proposed') {
            throw new HttpException(409, 'A running or paused experiment is stopped with a rollback, not a cancel.', 'conflict');
        }
        Db::update('pricing_experiments', ['status' => 'cancelled', 'ended_at' => Clock::sql(), 'updated_at' => Clock::sql()], 'id = ?', [$id]);
        Audit::log('pricing.experiment_cancelled', 'pricing_experiment', $id, ['reason' => $reason], $req, $admin);
        self::log($id, (int) $exp['service_id'], 'cancelled', true, $reason !== '' ? $reason : 'Cancelled before it started.', null, 'admin');
        self::resetCache();
        return self::experiment($id);
    }

    private static function requireExperiment(int $id): array
    {
        return Db::one('SELECT * FROM pricing_experiments WHERE id = ?', [$id]) ?? throw new HttpException(404, 'Experiment not found.');
    }

    // ═══════════════════════════ evaluation ═════════════════════════════════

    /**
     * Evaluate every running experiment: recompute real cohort metrics, check every rollback trigger, and either conclude a
     * decisive result or keep measuring. $commit = false (the scheduled job's own dry-run) computes and logs everything but
     * never changes an experiment's status or a service's price.
     *
     * @return array<string,mixed>
     */
    public static function evaluateAll(bool $commit = true): array
    {
        $ids = Db::col("SELECT id FROM pricing_experiments WHERE status = 'running'");
        $out = ['evaluated' => 0, 'rolled_back' => 0, 'concluded' => 0, 'experiments' => []];
        foreach ($ids as $id) {
            $r = self::evaluate((int) $id, $commit);
            $out['evaluated']++;
            if ($r['action'] === 'rolled_back') {
                $out['rolled_back']++;
            } elseif ($r['action'] === 'concluded') {
                $out['concluded']++;
            }
            $out['experiments'][] = ['id' => (int) $id] + $r;
        }
        return $out;
    }

    /** @return array{action:string,decision:?string,reason:?string} */
    public static function evaluate(int $id, bool $commit = true): array
    {
        $exp = self::requireExperiment($id);
        if ($exp['status'] !== 'running') {
            return ['action' => 'skipped', 'decision' => $exp['decision'], 'reason' => 'Not running.'];
        }
        $control = self::rollupCohort($id, 'control');
        $treatment = self::rollupCohort($id, 'treatment');
        $s = self::settings();
        $now = Clock::now();
        $action = 'measuring';
        $decision = null;
        $reason = null;

        if ($exp['mode'] === 'live') {
            $rollbackReason = self::checkRollbackTriggers($exp, $control, $treatment, $s);
            if ($rollbackReason !== null) {
                $action = 'rolled_back';
                $reason = $rollbackReason;
                if ($commit) {
                    self::applyRollback($exp, $rollbackReason, 'system');
                }
            } else {
                [$decision, $reason] = self::decide($exp, $control, $treatment, $s);
                $durationOver = $now >= (int) Clock::fromSql((string) $exp['ends_at']);
                if ($decision !== null && in_array($decision, ['winner', 'loser'], true)) {
                    $action = 'concluded';
                } elseif ($durationOver) {
                    $action = 'rolled_back';
                    $decision = 'insufficient_data';
                    $reason = 'Maximum experiment duration (' . $exp['max_duration_days'] . ' days) exceeded without a conclusive result.';
                    if ($commit) {
                        self::applyRollback($exp, $reason, 'system');
                    }
                }
                if ($action === 'concluded' && $commit) {
                    Db::update('pricing_experiments', [
                        'status' => 'concluded', 'decision' => $decision, 'decision_reason' => mb_substr((string) $reason, 0, 500),
                        'ended_at' => Clock::sql($now), 'last_evaluated_at' => Clock::sql($now), 'updated_at' => Clock::sql($now),
                    ], 'id = ?', [$id]);
                }
            }
        } else {
            // dry run: an A/A sanity check only — no customer price risk, no rollback triggers, just a timeline note
            if ($now >= (int) Clock::fromSql((string) $exp['ends_at'])) {
                $action = 'concluded';
                $decision = 'inconclusive';
                $reason = 'Dry run finished: both cohorts were shown the same price, so this measured the pipeline, not a price change. Switch an owner-approved experiment to live to test a real price.';
                if ($commit) {
                    Db::update('pricing_experiments', ['status' => 'concluded', 'decision' => $decision, 'decision_reason' => $reason, 'ended_at' => Clock::sql($now), 'last_evaluated_at' => Clock::sql($now), 'updated_at' => Clock::sql($now)], 'id = ?', [$id]);
                }
            }
        }

        if ($action === 'measuring' && $commit) {
            Db::update('pricing_experiments', ['last_evaluated_at' => Clock::sql($now)], 'id = ?', [$id]);
        }
        if ($action !== 'rolled_back') { // applyRollback already writes its own decision log
            self::log($id, (int) $exp['service_id'], $action === 'measuring' ? 'evaluated' : $action, !$commit, $reason ?? ('Re-evaluated: ' . $control['purchases'] . ' control / ' . $treatment['purchases'] . ' treatment verified purchases so far.'), ['control' => $control, 'treatment' => $treatment], 'cron');
        }
        if ($commit) {
            self::resetCache();
        }
        return ['action' => $action, 'decision' => $decision, 'reason' => $reason];
    }

    /** @param array<string,mixed> $exp @return string|null the rollback reason, or null when nothing is wrong */
    private static function checkRollbackTriggers(array $exp, array $control, array $treatment, array $s): ?string
    {
        $svc = Db::one('SELECT price_paise, smm_cost_paise, fulfil_cost_paise FROM services WHERE id = ?', [$exp['service_id']]);
        if ($svc === null) {
            return 'The service no longer exists.';
        }
        if ((int) $svc['price_paise'] !== (int) $exp['control_paise']) {
            return 'The service’s default price changed unexpectedly while this experiment was running.';
        }
        $snapshot = (json_decode((string) $exp['metrics_json'], true) ?: [])['cost_snapshot'] ?? null;
        if (is_array($snapshot)) {
            $tol = $s['cost_increase_tolerance_bps'];
            $smmBefore = (int) ($snapshot['smm_cost_paise'] ?? 0);
            $smmNow = (int) $svc['smm_cost_paise'];
            $fulfilBefore = $snapshot['fulfil_cost_paise'] !== null ? (int) $snapshot['fulfil_cost_paise'] : null;
            $fulfilNow = $svc['fulfil_cost_paise'] !== null ? (int) $svc['fulfil_cost_paise'] : null;
            $increased = ($smmBefore > 0 && Money::mulDiv($smmNow - $smmBefore, 10000, $smmBefore) > $tol)
                || ($fulfilBefore === null && $fulfilNow !== null) || ($fulfilBefore !== null && $fulfilNow === null)
                || ($fulfilBefore !== null && $fulfilNow !== null && $fulfilBefore > 0 && Money::mulDiv($fulfilNow - $fulfilBefore, 10000, $fulfilBefore) > $tol);
            if ($increased) {
                return 'The service’s cost increased beyond the approved threshold since the experiment started.';
            }
        }
        $bad = (int) Db::val("SELECT COUNT(*) FROM orders WHERE experiment_id = ? AND (price_cohort IS NULL OR price_version_id IS NULL)", [$exp['id']]);
        if ($bad > 0) {
            return 'Data integrity check failed: ' . $bad . ' order(s) linked to this experiment are missing their price attribution.';
        }
        if ($treatment['purchases'] > 0 && (int) $exp['profit_floor_paise'] >= 0) {
            $avg = Money::mulDiv($treatment['contribution_paise'], 1, $treatment['purchases']);
            if ($avg < (int) $exp['profit_floor_paise']) {
                return 'Contribution profit per order in the candidate cohort (' . Money::inr($avg, true) . ') fell below the configured floor (' . Money::inr((int) $exp['profit_floor_paise'], true) . ').';
            }
        }
        $minHalf = max(5, intdiv((int) $exp['min_sample_per_cohort'], 2));
        if ($control['checkout_starts'] >= $minHalf && $treatment['checkout_starts'] >= $minHalf) {
            $controlFail = Money::mulDiv($control['failed'], 10000, $control['checkout_starts']);
            $treatFail = Money::mulDiv($treatment['failed'], 10000, $treatment['checkout_starts']);
            if ($treatFail - $controlFail > $s['rollback_failure_gap_bps']) {
                return 'Payment or order failures are elevated in the candidate cohort (' . Money::tenthsToString((int) round($treatFail / 10)) . '% vs ' . Money::tenthsToString((int) round($controlFail / 10)) . '% in control).';
            }
        }
        if ($control['qualified'] >= $minHalf && $treatment['qualified'] >= $minHalf) {
            $controlConv = Money::mulDiv($control['purchases'], 10000, $control['qualified']);
            $treatConv = Money::mulDiv($treatment['purchases'], 10000, $treatment['qualified']);
            if ($controlConv > 0 && $treatConv < Money::mulDiv($controlConv, 10000 - $s['rollback_conversion_tolerance_bps'], 10000)) {
                return 'Verified purchase conversion in the candidate cohort declined beyond the configured tolerance.';
            }
        }
        return null;
    }

    /** @return array{0:?string,1:?string} [decision, reason] */
    private static function decide(array $exp, array $control, array $treatment, array $s): array
    {
        $minSample = (int) $exp['min_sample_per_cohort'];
        if ($control['qualified'] < $minSample || $treatment['qualified'] < $minSample) {
            return [null, 'Not enough qualified visitors yet (control ' . $control['qualified'] . ', treatment ' . $treatment['qualified'] . ' of ' . $minSample . ' needed per cohort).'];
        }
        $controlPerVisitor = Money::mulDiv($control['contribution_paise'], 10000, $control['qualified']);
        $treatPerVisitor = Money::mulDiv($treatment['contribution_paise'], 10000, $treatment['qualified']);
        $margin = $s['decision_margin_bps'];
        if ($controlPerVisitor <= 0 && $treatPerVisitor <= 0) {
            return ['inconclusive', 'Neither cohort is contributing positive profit per visitor yet.'];
        }
        $better = $controlPerVisitor !== 0 ? Money::mulDiv($treatPerVisitor - $controlPerVisitor, 10000, abs($controlPerVisitor)) : ($treatPerVisitor > 0 ? 10000 : 0);
        if ($better >= $margin) {
            return ['winner', 'The candidate price contributes ' . Money::tenthsToString((int) round($better / 10)) . '% more profit per qualified visitor than the control price.'];
        }
        if (-$better >= $margin) {
            return ['loser', 'The candidate price contributes ' . Money::tenthsToString((int) round(-$better / 10)) . '% less profit per qualified visitor than the control price.'];
        }
        return ['inconclusive', 'The difference between the candidate and control price is within the configured decision margin.'];
    }

    /** @return array{assigned:int,qualified:int,service_views:int,checkout_starts:int,purchases:int,failed:int,revenue_paise:int,contribution_paise:int} */
    private static function rollupCohort(int $expId, string $cohort): array
    {
        $a = Db::one(
            'SELECT COUNT(*) AS assigned, SUM(CASE WHEN qualified_at IS NOT NULL THEN 1 ELSE 0 END) AS qualified, '
            . 'SUM(CASE WHEN service_viewed_at IS NOT NULL THEN 1 ELSE 0 END) AS viewed, MAX(price_version_id) AS pvid, MAX(price_paise) AS pp '
            . 'FROM pricing_assignments WHERE experiment_id = ? AND cohort = ?',
            [$expId, $cohort]
        ) ?? ['assigned' => 0, 'qualified' => 0, 'viewed' => 0, 'pvid' => null, 'pp' => 0];
        $o = Db::one(
            'SELECT COUNT(*) AS starts, SUM(CASE WHEN paid_at IS NOT NULL THEN 1 ELSE 0 END) AS purchases, '
            . "SUM(CASE WHEN status IN ('FAILED','CANCELLED') THEN 1 ELSE 0 END) AS failed, "
            . 'SUM(CASE WHEN paid_at IS NOT NULL THEN amount_paise - refunded_paise ELSE 0 END) AS revenue, '
            . "SUM(CASE WHEN paid_at IS NOT NULL THEN (amount_paise - refunded_paise) - (CASE WHEN mode = 'AUTO' THEN provider_cost_paise ELSE 0 END) - " . Finance::linesSql('orders') . ' ELSE 0 END) AS contribution '
            . 'FROM orders WHERE experiment_id = ? AND price_cohort = ?',
            [$expId, $cohort]
        ) ?? ['starts' => 0, 'purchases' => 0, 'failed' => 0, 'revenue' => 0, 'contribution' => 0];
        $row = [
            'assigned' => (int) $a['assigned'], 'qualified' => (int) $a['qualified'], 'service_views' => (int) $a['viewed'],
            'checkout_starts' => (int) $o['starts'], 'purchases' => (int) $o['purchases'], 'failed' => (int) $o['failed'],
            'revenue_paise' => (int) $o['revenue'], 'contribution_paise' => (int) $o['contribution'],
        ];
        Db::exec(
            'INSERT INTO pricing_experiment_cohorts (experiment_id, cohort, price_version_id, price_paise, assigned, qualified, service_views, checkout_starts, purchases, revenue_paise, contribution_paise, computed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE price_version_id = VALUES(price_version_id), price_paise = VALUES(price_paise), assigned = VALUES(assigned), '
            . 'qualified = VALUES(qualified), service_views = VALUES(service_views), checkout_starts = VALUES(checkout_starts), purchases = VALUES(purchases), revenue_paise = VALUES(revenue_paise), '
            . 'contribution_paise = VALUES(contribution_paise), computed_at = VALUES(computed_at)',
            [$expId, $cohort, $a['pvid'] !== null ? (int) $a['pvid'] : null, (int) $a['pp'], $row['assigned'], $row['qualified'], $row['service_views'], $row['checkout_starts'], $row['purchases'], $row['revenue_paise'], $row['contribution_paise'], Clock::sql()]
        );
        return $row;
    }

    /** @param array<string,mixed>|null $metrics */
    private static function log(int $expId, int $serviceId, string $decision, bool $dryRun, string $reason, ?array $metrics, string $actor): void
    {
        Db::insert('pricing_decision_logs', [
            'experiment_id' => $expId, 'service_id' => $serviceId, 'decision' => substr($decision, 0, 24), 'dry_run' => $dryRun ? 1 : 0,
            'reason' => mb_substr($reason, 0, 500), 'metrics_json' => $metrics !== null ? mb_substr((string) json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 65000) : null,
            'actor' => $actor, 'created_at' => Clock::sql(),
        ]);
    }

    // ═══════════════════════════ visitor-facing resolution ══════════════════

    /** service_id => the one running experiment eligible to show a visitor a different price right now. */
    private static function activeExperimentsByService(): array
    {
        if (self::$activeCache !== null) {
            return self::$activeCache;
        }
        if ((bool) Settings::get('pricing.global_pause', false)) {
            return self::$activeCache = [];
        }
        $rows = Db::all(
            "SELECT e.* FROM pricing_experiments e JOIN services s ON s.id = e.service_id "
            . "WHERE e.status = 'running' AND s.pricing_paused = 0 AND s.deleted_at IS NULL"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['service_id']] = $r;
        }
        return self::$activeCache = $out;
    }

    private static function assignmentKey(string $anonId): string
    {
        return substr(hash_hmac('sha256', $anonId, Crypto::subKey('pricing-assignment')), 0, 32);
    }

    /**
     * The price to show/charge a given visitor for a service, right now. Stable for the lifetime of the visitor's assignment —
     * the same visitor is never shown a different price on a later page view while their assignment has not expired.
     *
     * @return array{price_paise:int,price_version_id:?int,experiment_id:?int,cohort:?string}
     */
    public static function resolveForService(int $serviceId, int $defaultPricePaise, ?string $anonId): array
    {
        $none = ['price_paise' => $defaultPricePaise, 'price_version_id' => null, 'experiment_id' => null, 'cohort' => null];
        if ($anonId === null || !preg_match('/^[a-f0-9]{32}$/', $anonId)) {
            return $none;
        }
        $exp = self::activeExperimentsByService()[$serviceId] ?? null;
        if ($exp === null) {
            return $none;
        }
        $a = self::assignment($exp, $anonId);
        if ($a === null) {
            return $none;
        }
        return ['price_paise' => (int) $a['price_paise'], 'price_version_id' => $a['price_version_id'] !== null ? (int) $a['price_version_id'] : null, 'experiment_id' => (int) $exp['id'], 'cohort' => (string) $a['cohort']];
    }

    /** @param array<string,mixed> $exp @return array<string,mixed>|null */
    private static function assignment(array $exp, string $anonId): ?array
    {
        $key = self::assignmentKey($anonId);
        $now = Clock::now();
        $row = Db::one('SELECT * FROM pricing_assignments WHERE experiment_id = ? AND assignment_key = ?', [$exp['id'], $key]);
        if ($row !== null && (int) Clock::fromSql((string) $row['expires_at']) > $now) {
            if ($row['service_viewed_at'] === null) {
                Db::exec('UPDATE pricing_assignments SET service_viewed_at = ? WHERE id = ? AND service_viewed_at IS NULL', [Clock::sql($now), $row['id']]);
            }
            return $row;
        }
        // deterministic bucket from the key + experiment id, so re-assignment after an expiry renewal lands in the same cohort
        $bucket = hexdec(substr(hash('sha256', $key . ':' . $exp['id']), 0, 8)) % 10000;
        $cohort = $bucket < (int) $exp['treatment_share_bps'] ? 'treatment' : 'control';
        $isLive = $exp['mode'] === 'live';
        $treat = $cohort === 'treatment' && $isLive;
        $priceVersionId = $treat ? $exp['candidate_version_id'] : $exp['control_version_id'];
        $pricePaise = $treat ? (int) $exp['candidate_paise'] : (int) $exp['control_paise'];
        $ttlDays = max(1, (int) Settings::get('pricing.assignment_ttl_days', 30));
        $assignedAt = Clock::sql($now);
        $expiresAt = Clock::sql($now + $ttlDays * 86400);
        Db::exec(
            'INSERT INTO pricing_assignments (experiment_id, assignment_key, cohort, price_version_id, price_paise, consent_state, assigned_at, expires_at, service_viewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE cohort = VALUES(cohort), price_version_id = VALUES(price_version_id), price_paise = VALUES(price_paise), assigned_at = VALUES(assigned_at), expires_at = VALUES(expires_at), qualified_at = NULL, service_viewed_at = VALUES(service_viewed_at)',
            [$exp['id'], $key, $cohort, $priceVersionId, $pricePaise, 'implied', $assignedAt, $expiresAt, $assignedAt]
        );
        return ['id' => Db::val('SELECT id FROM pricing_assignments WHERE experiment_id = ? AND assignment_key = ?', [$exp['id'], $key]), 'cohort' => $cohort, 'price_version_id' => $priceVersionId, 'price_paise' => $pricePaise, 'service_viewed_at' => $assignedAt];
    }

    /** The visitor passed the 3-second qualification rule (Tracking::qualify): mark it on every one of their live assignments. */
    public static function onVisitorQualified(string $anonId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $anonId)) {
            return;
        }
        $key = self::assignmentKey($anonId);
        Db::exec('UPDATE pricing_assignments SET qualified_at = ? WHERE assignment_key = ? AND qualified_at IS NULL AND expires_at > ?', [Clock::sql(), $key, Clock::sql()]);
    }

    // ═══════════════════════════ admin: experiments, profit guard, learning history ══

    /**
     * @param array{status?:string,page?:int} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function experiments(array $f = []): array
    {
        $where = ['1 = 1'];
        $args = [];
        $status = (string) ($f['status'] ?? '');
        if ($status === 'active') {
            $where[] = "e.status IN ('proposed','running','paused')";
        } elseif ($status === 'ended') {
            $where[] = "e.status IN ('concluded','rolled_back','cancelled')";
        } elseif (in_array($status, ['proposed', 'running', 'paused', 'concluded', 'rolled_back', 'cancelled'], true)) {
            $where[] = 'e.status = ?';
            $args[] = $status;
        }
        $w = implode(' AND ', $where);
        $per = 20;
        $total = (int) Db::val("SELECT COUNT(*) FROM pricing_experiments e WHERE {$w}", $args);
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $rows = Db::all(
            "SELECT e.*, s.title AS service_title, s.code AS service_code, p.slug AS platform_slug FROM pricing_experiments e "
            . "JOIN services s ON s.id = e.service_id JOIN platforms p ON p.id = s.platform_id WHERE {$w} "
            . "ORDER BY FIELD(e.status, 'running', 'paused', 'proposed', 'concluded', 'rolled_back', 'cancelled'), e.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per),
            $args
        );
        return ['rows' => array_map([self::class, 'experimentRow'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array<string,mixed> */
    public static function experiment(int $id): array
    {
        $r = Db::one(
            "SELECT e.*, s.title AS service_title, s.code AS service_code, p.slug AS platform_slug FROM pricing_experiments e "
            . 'JOIN services s ON s.id = e.service_id JOIN platforms p ON p.id = s.platform_id WHERE e.id = ?',
            [$id]
        ) ?? throw new HttpException(404, 'Experiment not found.');
        $row = self::experimentRow($r);
        $row['cohorts'] = Db::all('SELECT * FROM pricing_experiment_cohorts WHERE experiment_id = ?', [$id]);
        $row['logs'] = array_map(static fn(array $l): array => [
            'decision' => $l['decision'], 'dry_run' => (bool) $l['dry_run'], 'reason' => $l['reason'], 'actor' => $l['actor'], 'at' => Clock::iso($l['created_at']),
        ], Db::all('SELECT * FROM pricing_decision_logs WHERE experiment_id = ? ORDER BY id DESC LIMIT 50', [$id]));
        $row['applied'] = Db::val("SELECT COUNT(*) FROM price_audit_logs WHERE experiment_id = ? AND actor_type = 'experiment'", [$id]) > 0;
        return $row;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function experimentRow(array $r): array
    {
        $now = Clock::now();
        $ends = $r['ends_at'] !== null ? (int) Clock::fromSql((string) $r['ends_at']) : null;
        return [
            'id' => (int) $r['id'], 'service_id' => (int) $r['service_id'], 'service_title' => (string) $r['service_title'], 'service_code' => (string) $r['service_code'], 'platform' => (string) $r['platform_slug'],
            'status' => (string) $r['status'], 'mode' => (string) $r['mode'],
            'control_version_id' => $r['control_version_id'] !== null ? (int) $r['control_version_id'] : null, 'candidate_version_id' => $r['candidate_version_id'] !== null ? (int) $r['candidate_version_id'] : null,
            'control_paise' => (int) $r['control_paise'], 'control' => Money::inr((int) $r['control_paise'], true),
            'candidate_paise' => (int) $r['candidate_paise'], 'candidate' => Money::inr((int) $r['candidate_paise'], true),
            'treatment_share_pct' => round((int) $r['treatment_share_bps'] / 100, 2), 'min_sample_per_cohort' => (int) $r['min_sample_per_cohort'],
            'max_duration_days' => (int) $r['max_duration_days'], 'profit_floor_paise' => (int) $r['profit_floor_paise'],
            'decision' => $r['decision'], 'decision_reason' => $r['decision_reason'], 'confidence_bps' => $r['confidence_bps'] !== null ? (int) $r['confidence_bps'] : null,
            'created_at' => Clock::iso($r['created_at']), 'started_at' => Clock::iso($r['started_at']), 'ends_at' => Clock::iso($r['ends_at']),
            'ended_at' => Clock::iso($r['ended_at']), 'rolled_back_at' => Clock::iso($r['rolled_back_at']), 'rollback_reason' => $r['rollback_reason'],
            'cooldown_until' => Clock::iso($r['cooldown_until']), 'last_evaluated_at' => Clock::iso($r['last_evaluated_at']),
            'time_remaining_seconds' => $r['status'] === 'running' && $ends !== null ? max(0, $ends - $now) : null,
        ];
    }

    /** Profit Guard: pause controls, live experiments' risk status, and recent rollbacks — all from real rows. @return array<string,mixed> */
    public static function profitGuard(): array
    {
        $settings = self::settings();
        $running = Db::all(
            "SELECT e.*, s.title AS service_title, s.code AS service_code FROM pricing_experiments e JOIN services s ON s.id = e.service_id WHERE e.status = 'running' ORDER BY e.id DESC"
        );
        $live = [];
        foreach ($running as $r) {
            $control = Db::one('SELECT * FROM pricing_experiment_cohorts WHERE experiment_id = ? AND cohort = ?', [$r['id'], 'control']);
            $treatment = Db::one('SELECT * FROM pricing_experiment_cohorts WHERE experiment_id = ? AND cohort = ?', [$r['id'], 'treatment']);
            $avgContribution = $treatment !== null && (int) $treatment['purchases'] > 0 ? Money::mulDiv((int) $treatment['contribution_paise'], 1, (int) $treatment['purchases']) : null;
            $floor = (int) $r['profit_floor_paise'];
            $live[] = [
                'id' => (int) $r['id'], 'service_title' => (string) $r['service_title'], 'service_code' => (string) $r['service_code'], 'mode' => (string) $r['mode'],
                'profit_floor_paise' => $floor, 'treatment_avg_contribution_paise' => $avgContribution,
                'at_risk' => $avgContribution !== null && $avgContribution < $floor + max(100, intdiv($floor, 10)),
                'breached' => $avgContribution !== null && $avgContribution < $floor,
                'control' => $control, 'treatment' => $treatment, 'last_evaluated_at' => Clock::iso($r['last_evaluated_at']),
            ];
        }
        $services = Db::all(
            "SELECT s.id, s.title, s.code, s.pricing_paused FROM services s WHERE s.mode = 'MANUAL' AND s.deleted_at IS NULL AND s.adaptive_enabled = 1 ORDER BY s.title"
        );
        $rollbacks = Db::all(
            "SELECT e.*, s.title AS service_title, s.code AS service_code FROM pricing_experiments e JOIN services s ON s.id = e.service_id "
            . "WHERE e.status = 'rolled_back' ORDER BY e.rolled_back_at DESC LIMIT 20"
        );
        return [
            'global_pause' => $settings['global_pause'],
            'services' => array_map(static fn(array $s): array => ['id' => (int) $s['id'], 'title' => (string) $s['title'], 'code' => (string) $s['code'], 'paused' => (bool) $s['pricing_paused']], $services),
            'live_experiments' => $live,
            'recent_rollbacks' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'], 'service_title' => (string) $r['service_title'], 'service_code' => (string) $r['service_code'],
                'reason' => $r['rollback_reason'], 'at' => Clock::iso($r['rolled_back_at']), 'cooldown_until' => Clock::iso($r['cooldown_until']),
            ], $rollbacks),
            'alerts' => Alerts::list(['type' => 'pricing.rollback', 'status' => 'all'])['rows'],
        ];
    }

    /**
     * AI Learning History: every concluded, rolled-back or cancelled experiment with its evidence. Real rows only.
     *
     * @param array{page?:int} $f
     * @return array<string,mixed>
     */
    public static function learningHistory(array $f = []): array
    {
        $per = 15;
        $total = (int) Db::val("SELECT COUNT(*) FROM pricing_experiments WHERE status IN ('concluded','rolled_back','cancelled')");
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $rows = Db::all(
            "SELECT e.*, s.title AS service_title, s.code AS service_code, p.slug AS platform_slug FROM pricing_experiments e "
            . "JOIN services s ON s.id = e.service_id JOIN platforms p ON p.id = s.platform_id "
            . "WHERE e.status IN ('concluded','rolled_back','cancelled') ORDER BY COALESCE(e.ended_at, e.rolled_back_at) DESC LIMIT {$per} OFFSET " . (($page - 1) * $per)
        );
        $out = [];
        foreach ($rows as $r) {
            $control = Db::one('SELECT * FROM pricing_experiment_cohorts WHERE experiment_id = ? AND cohort = ?', [$r['id'], 'control']);
            $treatment = Db::one('SELECT * FROM pricing_experiment_cohorts WHERE experiment_id = ? AND cohort = ?', [$r['id'], 'treatment']);
            $out[] = self::experimentRow($r) + ['control_metrics' => $control, 'treatment_metrics' => $treatment, 'applied' => Db::val("SELECT COUNT(*) FROM price_audit_logs WHERE experiment_id = ? AND actor_type = 'experiment'", [$r['id']]) > 0];
        }
        $decisions = Db::one(
            "SELECT SUM(decision = 'winner') AS winners, SUM(decision = 'loser') AS losers, SUM(decision IN ('inconclusive','insufficient_data')) AS inconclusive, "
            . "SUM(status = 'rolled_back') AS rolled_back FROM pricing_experiments WHERE status IN ('concluded','rolled_back')"
        ) ?? ['winners' => 0, 'losers' => 0, 'inconclusive' => 0, 'rolled_back' => 0];
        return [
            'rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'summary' => ['winners' => (int) $decisions['winners'], 'losers' => (int) $decisions['losers'], 'inconclusive' => (int) $decisions['inconclusive'], 'rolled_back' => (int) $decisions['rolled_back']],
        ];
    }
}
