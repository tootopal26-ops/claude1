<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Tz;

/**
 * The advertising budget & forecast screen deferred from Phase 8 (see docs/ADAPTIVE-PRICING.md §9):
 * a planned daily/monthly spend cap, and what that spend is expected to buy — qualified visitors,
 * customers, revenue and contribution — projected from REAL recent unit economics (App\Services\AdsIntel),
 * never a demand forecast. This never changes what actually gets spent or billed: it is planning
 * information only, read by an admin deciding a budget, same as the Price Experiment Simulator
 * (App\Services\Pricing) projects at real volume without touching a live price.
 *
 * Storage: `ad_campaign_settings` (migration 009_adaptive_pricing), unused until now. `kind` = 'default'
 * (the standing budget) or 'override' (a period_start..period_end scoped budget, e.g. a sale) — append-only
 * and audited like every other settings history table here: saving a new row of a kind revokes (never
 * deletes) whichever existing, not-yet-revoked row(s) of that kind would otherwise also apply.
 */
final class AdBudget
{
    public const MAX_DAILY_PAISE = 100_000_000;    // ₹10,00,000/day — a generous typo guard, not a real cap
    public const MAX_MONTHLY_PAISE = 1_000_000_000; // ₹1,00,00,000/month
    public const MAX_OVERRIDE_DAYS = 366;
    public const RECENT_WINDOW_DAYS = 30;

    /**
     * Today's and this calendar month's actual ad spend so far (`ads_spend_paise`, the same actual-beats-estimate figure the dashboard and
     * Finance use — never a separate computation), against today's effective budget. Read by the dashboard and the `ad_budget` watchdog
     * check; never written here.
     *
     * @return array{daily_budget_paise:?int,monthly_budget_paise:?int,source:string,today_paise:int,month_paise:int,month_start:string}
     */
    public static function spendSoFar(): array
    {
        $today = Tz::today();
        $monthStart = substr($today, 0, 8) . '01';
        $budget = self::current();
        $days = Analytics::rollup($monthStart, $today);
        $monthPaise = Analytics::sum($days)['ads_spend_paise'];
        $todayPaise = $days[$today]['ads_spend_paise'] ?? 0;
        return $budget + ['today_paise' => $todayPaise, 'month_paise' => $monthPaise, 'month_start' => $monthStart];
    }

    /** @return array<string,mixed> {daily_budget_paise,monthly_budget_paise,source,note,period_start,period_end} — the budget in force today */
    public static function current(): array
    {
        $today = Tz::today();
        $override = Db::one(
            "SELECT * FROM ad_campaign_settings WHERE kind = 'override' AND revoked_at IS NULL AND period_start <= ? AND period_end >= ? ORDER BY created_at DESC, id DESC LIMIT 1",
            [$today, $today]
        );
        $row = $override ?? Db::one("SELECT * FROM ad_campaign_settings WHERE kind = 'default' AND revoked_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1");
        if ($row === null) {
            return ['daily_budget_paise' => null, 'monthly_budget_paise' => null, 'source' => 'none', 'note' => null, 'period_start' => null, 'period_end' => null];
        }
        $s = json_decode((string) $row['settings_json'], true) ?: [];
        return [
            'daily_budget_paise' => isset($s['daily_budget_paise']) ? (int) $s['daily_budget_paise'] : null,
            'monthly_budget_paise' => isset($s['monthly_budget_paise']) ? (int) $s['monthly_budget_paise'] : null,
            'source' => (string) $row['kind'], 'note' => $row['note'],
            'period_start' => $row['period_start'], 'period_end' => $row['period_end'],
        ];
    }

    /**
     * @param array{scope?:mixed,period_start?:mixed,period_end?:mixed,daily_budget?:mixed,monthly_budget?:mixed,note?:mixed} $in
     * @param array{id:int,username:string} $admin
     */
    public static function save(array $in, Request $req, array $admin): array
    {
        $errors = [];
        $scope = $in['scope'] ?? 'default';
        if (!in_array($scope, ['default', 'override'], true)) {
            $errors['scope'] = 'Choose a budget scope.';
        }
        $start = null;
        $end = null;
        if ($scope === 'override') {
            $start = is_string($in['period_start'] ?? null) ? trim($in['period_start']) : '';
            $end = is_string($in['period_end'] ?? null) ? trim($in['period_end']) : '';
            if (!Tz::isDay($start)) {
                $errors['period_start'] = 'Choose a start date.';
            }
            if (!Tz::isDay($end)) {
                $errors['period_end'] = 'Choose an end date.';
            }
            if (!isset($errors['period_start'], $errors['period_end']) && !$errors && $end < $start) {
                $errors['period_end'] = 'The end date must be on or after the start date.';
            } elseif (!$errors && $start !== '' && $end !== '' && Tz::isDay($start) && Tz::isDay($end) && Tz::daysBetween($start, $end) + 1 > self::MAX_OVERRIDE_DAYS) {
                $errors['period_end'] = 'An override can cover at most ' . self::MAX_OVERRIDE_DAYS . ' days.';
            }
        }
        $daily = self::parseOptionalRupees($in['daily_budget'] ?? null, self::MAX_DAILY_PAISE, 'daily_budget', $errors, '/day');
        $monthly = self::parseOptionalRupees($in['monthly_budget'] ?? null, self::MAX_MONTHLY_PAISE, 'monthly_budget', $errors, '/month');
        $note = is_string($in['note'] ?? null) ? trim($in['note']) : '';
        if (mb_strlen($note) > 255) {
            $errors['note'] = 'Keep the note under 255 characters.';
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }

        $now = Clock::sql();
        $json = json_encode(['daily_budget_paise' => $daily, 'monthly_budget_paise' => $monthly], JSON_UNESCAPED_SLASHES);
        $superseded = [];
        Db::tx(function () use ($scope, $start, $end, $json, $note, $admin, $now, &$superseded): void {
            // locked for the rest of the transaction, so two concurrent saves can never both see the same not-yet-revoked rows as current
            $superseded = $scope === 'default'
                ? Db::col("SELECT id FROM ad_campaign_settings WHERE kind = 'default' AND revoked_at IS NULL FOR UPDATE")
                : Db::col("SELECT id FROM ad_campaign_settings WHERE kind = 'override' AND revoked_at IS NULL AND period_start <= ? AND period_end >= ? FOR UPDATE", [$end, $start]);
            if ($superseded) {
                Db::exec('UPDATE ad_campaign_settings SET revoked_at = ? WHERE id IN (' . Db::in($superseded) . ')', array_merge([$now], $superseded));
            }
            Db::insert('ad_campaign_settings', [
                'kind' => $scope, 'period_start' => $scope === 'override' ? $start : null, 'period_end' => $scope === 'override' ? $end : null,
                'settings_json' => $json, 'note' => $note !== '' ? $note : null, 'set_by' => $admin['id'], 'created_at' => $now,
            ]);
        });
        Audit::log('settings.ad_budget', 'ad_campaign_settings', null, [
            'scope' => $scope, 'period_start' => $start, 'period_end' => $end,
            'daily_budget' => $daily !== null ? Money::plain($daily) : null, 'monthly_budget' => $monthly !== null ? Money::plain($monthly) : null,
        ], $req, $admin);
        return self::current();
    }

    /** Explicit "no cap" default — the documented starting state, kept reversible/audited like any other save. */
    public static function reset(Request $req, array $admin): array
    {
        return self::save(['scope' => 'default', 'daily_budget' => null, 'monthly_budget' => null], $req, $admin);
    }

    /** Ends an active override's period early (its default underneath, if any, applies again from today). */
    public static function revokeOverride(Request $req, array $admin): array
    {
        $today = Tz::today();
        $cur = Db::one("SELECT id, period_start, period_end FROM ad_campaign_settings WHERE kind = 'override' AND revoked_at IS NULL AND period_start <= ? AND period_end >= ? ORDER BY created_at DESC, id DESC LIMIT 1", [$today, $today]);
        if ($cur === null) {
            throw new HttpException(409, 'There is no active budget override to end.', 'no_current');
        }
        Db::exec('UPDATE ad_campaign_settings SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [Clock::sql(), $cur['id']]);
        Audit::log('settings.ad_budget.revoke_override', 'ad_campaign_settings', (int) $cur['id'], ['period_start' => $cur['period_start'], 'period_end' => $cur['period_end']], $req, $admin);
        return self::current();
    }

    /** @return list<array<string,mixed>> */
    public static function history(int $limit = 15): array
    {
        $rows = Db::all(
            'SELECT s.*, a.username FROM ad_campaign_settings s LEFT JOIN admins a ON a.id = s.set_by ORDER BY s.created_at DESC, s.id DESC LIMIT ?',
            [$limit]
        );
        return array_map(static function (array $r): array {
            $s = json_decode((string) $r['settings_json'], true) ?: [];
            return [
                'id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'period_start' => $r['period_start'], 'period_end' => $r['period_end'],
                'daily_budget_paise' => isset($s['daily_budget_paise']) ? (int) $s['daily_budget_paise'] : null,
                'monthly_budget_paise' => isset($s['monthly_budget_paise']) ? (int) $s['monthly_budget_paise'] : null,
                'note' => $r['note'], 'set_by' => $r['username'] ?: 'system', 'created_at' => Clock::iso((string) $r['created_at']),
                'revoked_at' => $r['revoked_at'] !== null ? Clock::iso((string) $r['revoked_at']) : null,
            ];
        }, $rows);
    }

    /**
     * What a planned spend is expected to buy, projected from the last 30 days' real unit economics —
     * shop-wide, or for one named campaign. Never a demand forecast: it holds today's cost-per-qualified-
     * visitor, conversion rate and revenue-per-customer constant and simply scales them to the budget.
     *
     * @return array<string,mixed>
     */
    public static function forecast(int $budgetPaise, string $campaign = ''): array
    {
        if ($budgetPaise < 0) {
            throw new HttpException(422, 'Enter a budget of ₹0 or more.', 'invalid', ['fields' => ['budget' => 'Enter a budget of ₹0 or more.']]);
        }
        $to = Tz::today();
        $from = Tz::addDays($to, -(self::RECENT_WINDOW_DAYS - 1));
        $filter = ['range' => 'custom', 'from' => $from, 'to' => $to];
        if ($campaign !== '') {
            $filter['campaign'] = $campaign;
        }
        $o = AdsIntel::overview($filter);
        $cards = $o['cards'];

        $costPerQualified = $cards['cost_per_qualified'] ?? Ads::cpcPaise();
        $customers = (int) $cards['customers'];
        $revenuePerCustomer = $customers > 0 ? Money::mulDiv((int) $cards['attributed_revenue'], 1, $customers) : null;
        $contributionPerCustomer = $customers > 0 ? Money::mulDiv((int) $cards['attributed_revenue'] - (int) $cards['attributed_costs'], 1, $customers) : null;
        $conversionTenths = $cards['conversion_tenths'];

        $qualifiedForecast = $costPerQualified > 0 ? intdiv($budgetPaise, $costPerQualified) : 0;
        $customersForecast = $conversionTenths !== null ? (int) floor($qualifiedForecast * $conversionTenths / 1000) : null;
        $revenueForecast = $customersForecast !== null && $revenuePerCustomer !== null ? $customersForecast * $revenuePerCustomer : null;
        $contributionForecast = $customersForecast !== null && $contributionPerCustomer !== null ? ($customersForecast * $contributionPerCustomer) - $budgetPaise : null;

        return [
            'budget_paise' => $budgetPaise, 'campaign' => $campaign, 'window' => ['from' => $from, 'to' => $to, 'days' => self::RECENT_WINDOW_DAYS],
            'basis' => [
                'cost_per_qualified_paise' => $costPerQualified, 'cost_per_qualified_is_real' => $cards['cost_per_qualified'] !== null,
                'conversion_tenths' => $conversionTenths, 'revenue_per_customer_paise' => $revenuePerCustomer,
                'contribution_per_customer_paise' => $contributionPerCustomer, 'customers_in_window' => $customers,
                'confidence' => $cards['confidence'],
            ],
            'qualified_visitors' => $qualifiedForecast, 'customers' => $customersForecast,
            'revenue_paise' => $revenueForecast, 'contribution_paise' => $contributionForecast,
        ];
    }

    private static function parseOptionalRupees(mixed $in, int $max, string $field, array &$errors, string $unit): ?int
    {
        if ($in === null || $in === '') {
            return null;
        }
        if (!is_string($in) && !is_int($in) && !is_float($in)) {
            $errors[$field] = 'Enter an amount in rupees, or leave it blank for no cap.';
            return null;
        }
        try {
            $v = Money::parseRupees($in);
        } catch (\InvalidArgumentException) {
            $errors[$field] = 'Enter an amount in rupees with at most two decimals, or leave it blank for no cap.';
            return null;
        }
        if ($v <= 0) {
            $errors[$field] = 'Enter an amount greater than ₹0, or leave it blank for no cap.';
            return null;
        }
        if ($v > $max) {
            $errors[$field] = 'That budget is larger than ' . Money::inr($max, true) . $unit . '.';
            return null;
        }
        return $v;
    }
}
