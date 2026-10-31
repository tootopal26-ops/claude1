<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Profit;
use App\Support\Status;
use App\Support\Tz;

/**
 * Everything the admin dashboard shows, as one JSON-ready structure.
 *
 * All money is integer paise; percentages are integer TENTHS of a percent
 * (592 = 59.2 %). Rendering/formatting is the browser's job.
 *
 * Filters (platform / mode) narrow the ORDER-based numbers. Visitor and
 * advertising numbers are site-wide: an ad click cannot be attributed to the
 * product a visitor later bought, so when a filter is active the net-profit
 * figure is reported BEFORE ads and says so (ads_attributable = false).
 */
final class Dashboard
{
    private const MAX_SPAN_DAYS = 1830;

    /**
     * @param array{range?:string,from?:string,to?:string,platform?:string,mode?:string,payment?:string} $f
     * @return array<string,mixed>
     */
    public static function build(array $f): array
    {
        $today = Tz::today();
        [$from, $to, $rangeKey] = self::resolveRange($f, $today);
        $platformSlug = (string) ($f['platform'] ?? '');
        $platformId = null;
        if ($platformSlug !== '') {
            $pid = Db::val('SELECT id FROM platforms WHERE slug = ?', [$platformSlug]);
            if ($pid === null) {
                throw new HttpException(422, 'Unknown platform.', 'invalid', ['fields' => ['platform' => 'Unknown platform.']]);
            }
            $platformId = (int) $pid;
        }
        $mode = in_array($f['mode'] ?? '', ['AUTO', 'MANUAL'], true) ? (string) $f['mode'] : null;
        $payment = in_array($f['payment'] ?? '', ['success', 'pending', 'failed', 'expired', 'refunded'], true) ? (string) $f['payment'] : '';
        $filtered = $platformId !== null || $mode !== null;

        $days = $filtered ? Analytics::computeDays($from, $to, $platformId, $mode) : Analytics::rollup($from, $to);
        // visitor + advertising numbers are always site-wide; so are chargebacks and business expenses, which no platform or order type owns
        if ($filtered) {
            $site = Analytics::rollup($from, $to);
            foreach ($days as $d => &$m) {
                foreach (['ads_spend_paise', 'ads_actual_paise', 'ads_estimated_paise', 'ads_actual_day', 'chargebacks_paise', 'expenses_paise', 'visitors', 'qualified_visitors', 'order_visitors', 'paid_visitors', 'desktop_visitors', 'mobile_visitors', 'tablet_visitors', 'src_instagram', 'src_youtube', 'src_direct', 'src_other'] as $k) {
                    $m[$k] = $site[$d][$k];
                }
                $m['net_profit_paise'] = $m['gross_profit_paise'];           // before the costs that belong to the whole business (see $adsAttributable)
            }
            unset($m);
        }

        $bucket = $from === $to ? 'hour' : (Tz::daysBetween($from, $to) <= 120 ? 'day' : (Tz::daysBetween($from, $to) <= 800 ? 'week' : 'month'));
        $series = $bucket === 'hour' ? self::hourly($from, $platformId, $mode) : self::bucketize($days, $bucket);
        $totals = Analytics::sum($days);
        $adsAttributable = !$filtered;
        $breakdown = Profit::breakdown(
            $totals['revenue_paise'], $totals['smm_spend_paise'], $adsAttributable ? $totals['ads_spend_paise'] : 0,
            $totals['fulfil_cost_paise'] + $totals['payment_fee_paise'] + $totals['other_cost_paise'],
            $adsAttributable ? $totals['chargebacks_paise'] + $totals['expenses_paise'] : 0
        );

        return [
            'meta' => [
                'generated_at' => gmdate('c'), 'today' => $today, 'timezone' => Tz::name(), 'timezone_label' => Tz::label(),
                'range' => ['key' => $rangeKey, 'from' => $from, 'to' => $to, 'bucket' => $bucket, 'days' => Tz::daysBetween($from, $to) + 1],
                'filters' => ['platform' => $platformSlug, 'mode' => $mode ?? '', 'payment' => $payment], 'filtered' => $filtered,
                'ads_attributable' => $adsAttributable, 'cpc_paise' => Ads::cpcPaise(), 'qualify_seconds' => Ads::current()['qualify_seconds'], 'first_day' => Analytics::firstDay(),
                'platforms' => array_map(static fn(array $p): array => ['slug' => $p['slug'], 'name' => $p['name']], Catalog::platforms(false)),
            ],
            'kpis' => self::kpis($today),
            'totals' => $totals + ['conv_order_tenths' => self::rate($totals['order_visitors'], $totals['visitors']), 'conv_paid_tenths' => self::rate($totals['paid_visitors'], $totals['visitors'])],
            'breakdown' => $breakdown,
            'labels' => FinanceReport::labels($totals, $filtered, $days),
            'series' => $series,
            'visitors' => self::visitorBlock($totals, $today),
            'performance' => self::performance($from, $to, $platformId, $mode, $totals, $breakdown),
            'smm' => self::smmSpend($from, $to, $platformId, $mode),
            'payments' => self::paymentStats($from, $to, $platformId, $mode, $totals),
            'recent' => self::recent($platformId, $mode, $payment),
            'command_center' => self::commandCenter(),
        ];
    }

    /**
     * The command-center summary: a sales target's pacing, today's advertising spend against its budget, and adaptive pricing's own
     * risk status — each read from its own service (never a separate computation), so the dashboard is the one screen that shows the
     * whole business's state. Stripped by role in withoutAds() / withoutPricing(), same as withoutFinance() strips profit and cost figures.
     *
     * @return array{sales_target:array<string,mixed>,ad_budget:array<string,mixed>,pricing:array<string,mixed>}
     */
    private static function commandCenter(): array
    {
        $pg = Pricing::profitGuard();
        $live = $pg['live_experiments'];
        return [
            'sales_target' => SalesTarget::progress(),
            'ad_budget' => AdBudget::spendSoFar(),
            'pricing' => [
                'global_pause' => $pg['global_pause'], 'live_count' => count($live),
                'at_risk_count' => count(array_filter($live, static fn(array $e): bool => $e['at_risk'])),
                'breached_count' => count(array_filter($live, static fn(array $e): bool => $e['breached'])),
                'recent_rollback_count' => count($pg['recent_rollbacks']),
            ],
        ];
    }

    /**
     * The dashboard for a role that may not see profit or costs (permission finance.view): every cost, profit, margin and advertising figure is
     * removed from the data — not merely hidden by the page — so nothing is left to read from the response.
     *
     * @param array<string,mixed> $d Dashboard::build()
     * @return array<string,mixed>
     */
    public static function withoutFinance(array $d): array
    {
        $d['meta']['finance'] = false;
        unset($d['meta']['cpc_paise']);
        foreach (['today', 'lifetime'] as $scope) {
            foreach (['profit', 'gross_profit', 'smm', 'ads'] as $k) {
                unset($d['kpis'][$scope][$k]);
            }
        }
        foreach (['profit', 'smm', 'ads'] as $k) {
            unset($d['kpis']['spark'][$k]);
        }
        $d['breakdown'] = null;
        $d['labels'] = null;
        $d['smm'] = null;
        foreach ($d['series'] as $i => $point) {
            unset($d['series'][$i]['gross_profit'], $d['series'][$i]['net_profit'], $d['series'][$i]['ads'], $d['series'][$i]['smm']);
        }
        foreach (['smm_spend_paise', 'fulfil_cost_paise', 'payment_fee_paise', 'other_cost_paise', 'chargebacks_paise', 'expenses_paise', 'gross_profit_paise', 'ads_spend_paise', 'ads_actual_paise',
            'ads_estimated_paise', 'ads_actual_day', 'net_profit_paise', 'auto_profit_paise', 'manual_profit_paise', 'orders_cost_estimated', 'orders_cost_incomplete'] as $k) {
            unset($d['totals'][$k]);
        }
        $p = &$d['performance'];
        $p['best_profit'] = null;
        $p['lowest_profit'] = null;
        $p['avg_profit_paise'] = null;
        foreach (['top_services', 'platforms'] as $list) {
            foreach ($p[$list] as $i => $row) {
                unset($p[$list][$i]['profit'], $p[$list][$i]['margin_tenths']);
            }
        }
        foreach (['best_revenue', 'best_platform'] as $one) {
            if (is_array($p[$one] ?? null)) {
                unset($p[$one]['profit'], $p[$one]['margin_tenths']);
            }
        }
        unset($p['auto_vs_manual']['auto']['profit'], $p['auto_vs_manual']['manual']['profit']);
        unset($p);
        return $d;
    }

    /** For a role without ads.view: the sales target and ad budget command-center cards are removed, not merely hidden. */
    public static function withoutAds(array $d): array
    {
        unset($d['command_center']['sales_target'], $d['command_center']['ad_budget']);
        return $d;
    }

    /** For a role without pricing.view: the adaptive-pricing command-center card is removed, not merely hidden. */
    public static function withoutPricing(array $d): array
    {
        unset($d['command_center']['pricing']);
        return $d;
    }

    /**
     * The date range a screen asked for: today · 7d · 30d · 90d · lifetime · custom (from / to, corrected if reversed, never in the future).
     *
     * @param array<string,mixed> $f
     * @return array{0:string,1:string,2:string} from, to, the range key
     */
    public static function resolveRange(array $f, string $today): array
    {
        $key = (string) ($f['range'] ?? '7d');
        switch ($key) {
            case 'today':
                return [$today, $today, 'today'];
            case '7d':
                return [Tz::addDays($today, -6), $today, '7d'];
            case '30d':
                return [Tz::addDays($today, -29), $today, '30d'];
            case '90d':
                return [Tz::addDays($today, -89), $today, '90d'];
            case 'lifetime':
                return [min(Analytics::firstDay(), $today), $today, 'lifetime'];
            case 'custom':
                $from = (string) ($f['from'] ?? '');
                $to = (string) ($f['to'] ?? '');
                if (!Tz::isDay($from) || !Tz::isDay($to)) {
                    throw new HttpException(422, 'Choose a valid start and end date.', 'invalid', ['fields' => ['from' => 'Choose a valid date.']]);
                }
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                if ($to > $today) {
                    $to = $today;
                }
                if ($from > $to || Tz::daysBetween($from, $to) > self::MAX_SPAN_DAYS) {
                    throw new HttpException(422, 'Choose a range of at most ' . self::MAX_SPAN_DAYS . ' days that does not start in the future.', 'invalid', ['fields' => ['from' => 'Range too large.']]);
                }
                return [$from, $to, 'custom'];
            default:
                throw new HttpException(422, 'Unknown date range.', 'invalid', ['fields' => ['range' => 'Unknown range.']]);
        }
    }

    private static function rate(int $num, int $den): int
    {
        return min(1000, Money::pctTenths($num, $den));
    }

    // ───────────────────────────── KPI cards ────────────────────────────────

    /** Site-wide Today and Lifetime figures with trend comparisons (never filtered). */
    private static function kpis(string $today): array
    {
        $first = Analytics::firstDay();
        $span = Tz::addDays($today, -13);
        $recent = Analytics::rollup($span, $today);   // 14 days: sparklines + 7d vs previous 7d
        $yesterday = $recent[Tz::addDays($today, -1)] ?? Analytics::zero();
        $todayM = $recent[$today] ?? Analytics::zero();

        $life = Analytics::zero();
        $upto = Tz::addDays($today, -1);
        if ($first <= $upto) {
            $life = Analytics::sum(Analytics::rollup($first, $upto));
        }
        $life = Analytics::sum([$life, $todayM]);

        $last7 = Analytics::sum(array_slice($recent, -7, 7, true));
        $prev7 = Analytics::sum(array_slice($recent, 0, 7, true));

        $keys = [
            'revenue'  => 'revenue_paise', 'profit' => 'net_profit_paise', 'orders' => 'orders_created', 'visitors' => 'visitors',
            'payments' => 'payments_success', 'ads' => 'ads_spend_paise', 'smm' => 'smm_spend_paise',
        ];
        $out = ['today' => [], 'lifetime' => [], 'spark' => []];
        foreach ($keys as $name => $col) {
            $out['today'][$name] = ['value' => $todayM[$col], 'prev' => $yesterday[$col], 'delta_tenths' => self::delta($todayM[$col], $yesterday[$col])];
            $lifeVal = $name === 'visitors' ? (int) Db::val('SELECT COUNT(*) FROM visitors') : $life[$col];
            $out['lifetime'][$name] = ['value' => $lifeVal, 'last7' => $last7[$col], 'prev7' => $prev7[$col], 'delta_tenths' => self::delta($last7[$col], $prev7[$col])];
            $out['spark'][$name] = array_values(array_map(static fn(array $m): int => $m[$col], $recent));
        }
        $out['today']['gross_profit'] = $todayM['gross_profit_paise'];
        $out['lifetime']['gross_profit'] = $life['gross_profit_paise'];
        $out['today']['qualified'] = $todayM['qualified_visitors'];
        $out['today']['orders_paid'] = $todayM['orders_paid'];
        $out['lifetime']['orders_paid'] = $life['orders_paid'];
        $out['lifetime']['qualified'] = $life['qualified_visitors'];
        return $out;
    }

    /** Change in tenths of a percent; null when there is no baseline. */
    private static function delta(int $now, int $before): ?int
    {
        if ($before === 0) {
            return $now === 0 ? 0 : null;
        }
        return Money::mulDiv($now - $before, 1000, abs($before));
    }

    // ───────────────────────────── series ───────────────────────────────────

    /**
     * @param array<string,array<string,int>> $days
     * @return list<array<string,mixed>>
     */
    private static function bucketize(array $days, string $bucket): array
    {
        $groups = [];
        foreach ($days as $day => $m) {
            $key = match ($bucket) {
                'week' => (new \DateTimeImmutable($day))->modify('monday this week')->format('Y-m-d'),
                'month' => substr($day, 0, 7) . '-01',
                default => $day,
            };
            $groups[$key][] = $m;
        }
        $out = [];
        foreach ($groups as $key => $list) {
            $out[] = self::point($key, Analytics::sum($list));
        }
        return $out;
    }

    /** @param array<string,int> $m @return array<string,mixed> */
    private static function point(string $key, array $m): array
    {
        return [
            'key' => $key, 'revenue' => $m['revenue_paise'], 'gross_profit' => $m['gross_profit_paise'], 'net_profit' => $m['net_profit_paise'],
            'orders' => $m['orders_created'], 'orders_paid' => $m['orders_paid'], 'visitors' => $m['visitors'], 'qualified' => $m['qualified_visitors'],
            'ads' => $m['ads_spend_paise'], 'smm' => $m['smm_spend_paise'],
            'conversion' => self::rate($m['paid_visitors'], $m['visitors']),
        ];
    }

    /** 24 hourly points for ONE day (hours in the business time zone). */
    private static function hourly(string $day, ?int $platformId, ?string $mode): array
    {
        $off = Tz::offsetSeconds(Tz::dayStart($day) + 43200);
        [$of, $op] = Analytics::orderFilter($platformId, $mode);
        $h = array_fill(0, 24, ['revenue' => 0, 'smm' => 0, 'lines' => 0, 'orders' => 0, 'orders_paid' => 0, 'visitors' => 0, 'qualified' => 0, 'ads' => 0]);

        foreach (Db::all(
            'SELECT HOUR(DATE_ADD(o.paid_at, INTERVAL ? SECOND)) AS h, COUNT(*) AS n, SUM(o.amount_paise - o.refunded_paise) AS rev, '
            . "SUM(CASE WHEN o.mode = 'AUTO' THEN o.provider_cost_paise ELSE 0 END) AS smm FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_day = ?" . $of . ' GROUP BY h',
            array_merge([$off, $day], $op)
        ) as $r) {
            $h[(int) $r['h']]['orders_paid'] = (int) $r['n'];
            $h[(int) $r['h']]['revenue'] = (int) $r['rev'];
            $h[(int) $r['h']]['smm'] = (int) $r['smm'];
        }
        foreach (Db::all(
            'SELECT HOUR(DATE_ADD(o.paid_at, INTERVAL ? SECOND)) AS h, SUM(c.amount_paise) AS line_sum FROM order_costs c JOIN orders o ON o.id = c.order_id '
            . 'WHERE c.superseded_at IS NULL AND o.paid_at IS NOT NULL AND o.paid_day = ?' . $of . ' GROUP BY h',
            array_merge([$off, $day], $op)
        ) as $r) {
            $h[(int) $r['h']]['lines'] = (int) $r['line_sum'];
        }
        foreach (Db::all('SELECT HOUR(DATE_ADD(o.created_at, INTERVAL ? SECOND)) AS h, COUNT(*) AS n FROM orders o WHERE o.created_day = ?' . $of . ' GROUP BY h', array_merge([$off, $day], $op)) as $r) {
            $h[(int) $r['h']]['orders'] = (int) $r['n'];
        }
        foreach (Db::all('SELECT HOUR(DATE_ADD(created_at, INTERVAL ? SECOND)) AS h, COUNT(*) AS n FROM visitor_days WHERE day = ? GROUP BY h', [$off, $day]) as $r) {
            $h[(int) $r['h']]['visitors'] = (int) $r['n'];
        }
        foreach (Db::all('SELECT HOUR(DATE_ADD(qualified_at, INTERVAL ? SECOND)) AS h, COUNT(*) AS n, SUM(cpc_paise) AS ads FROM visitor_days WHERE day = ? AND qualified = 1 GROUP BY h', [$off, $day]) as $r) {
            $h[(int) $r['h']]['qualified'] = (int) $r['n'];
            $h[(int) $r['h']]['ads'] = (int) $r['ads'];
        }
        // a day with ACTUAL ad spend carries that, not the CPC estimate (never both): spread over the hours in proportion to the qualified visitors
        $actual = AdSpend::byDay($day, $day)[$day] ?? null;
        if ($actual !== null) {
            $q = array_sum(array_column($h, 'qualified'));
            $left = $actual;
            $last = null;
            foreach ($h as $hour => &$m) {
                $m['ads'] = $q > 0 ? Money::mulDiv($actual, $m['qualified'], $q) : 0;
                $left -= $m['ads'];
                $last = $m['qualified'] > 0 ? $hour : $last;
            }
            unset($m);
            $h[$last ?? 0]['ads'] += $left;                                  // rounding (or a day with no qualified visitors) lands in one hour
        }
        $out = [];
        foreach ($h as $hour => $m) {
            $gross = $m['revenue'] - $m['smm'] - $m['lines'];
            $out[] = [
                'key' => sprintf('%02d:00', $hour), 'revenue' => $m['revenue'], 'gross_profit' => $gross, 'net_profit' => $gross - $m['ads'],
                'orders' => $m['orders'], 'orders_paid' => $m['orders_paid'], 'visitors' => $m['visitors'], 'qualified' => $m['qualified'],
                'ads' => $m['ads'], 'smm' => $m['smm'], 'conversion' => $m['qualified'] > 0 ? self::rate($m['orders_paid'], $m['qualified']) : 0,
            ];
        }
        return $out;
    }

    // ───────────────────────────── blocks ───────────────────────────────────

    private static function visitorBlock(array $t, string $today): array
    {
        $todayRow = Analytics::computeDays($today, $today)[$today];
        return [
            'today' => ['visitors' => $todayRow['visitors'], 'qualified' => $todayRow['qualified_visitors'], 'order_visitors' => $todayRow['order_visitors'], 'paid_visitors' => $todayRow['paid_visitors']],
            'lifetime_visitors' => (int) Db::val('SELECT COUNT(*) FROM visitors'),
            'range' => ['visitors' => $t['visitors'], 'qualified' => $t['qualified_visitors'], 'order_visitors' => $t['order_visitors'], 'paid_visitors' => $t['paid_visitors']],
            'conv_order_tenths' => self::rate($t['order_visitors'], $t['visitors']),
            'conv_paid_tenths' => self::rate($t['paid_visitors'], $t['visitors']),
            'devices' => ['desktop' => $t['desktop_visitors'], 'mobile' => $t['mobile_visitors'], 'tablet' => $t['tablet_visitors']],
            'sources' => ['instagram' => $t['src_instagram'], 'youtube' => $t['src_youtube'], 'direct' => $t['src_direct'], 'other' => $t['src_other']],
        ];
    }

    private static function performance(string $from, string $to, ?int $platformId, ?string $mode, array $t, array $breakdown): array
    {
        [$of, $op] = Analytics::orderFilter($platformId, $mode);
        $rows = Db::all(
            'SELECT o.service_id AS id, MAX(o.service_title) AS title, MAX(o.platform_slug) AS platform, MAX(o.mode) AS mode, COUNT(*) AS orders, '
            . 'SUM(' . Profit::SQL_REVENUE . ') AS revenue, SUM(' . Profit::SQL_GROSS . ') AS profit FROM orders o '
            . 'WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ?' . $of . ' GROUP BY o.service_id',
            array_merge([$from, $to], $op)
        );
        $services = array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'title' => (string) $r['title'], 'platform' => (string) $r['platform'], 'mode' => (string) $r['mode'],
            'orders' => (int) $r['orders'], 'revenue' => (int) $r['revenue'], 'profit' => (int) $r['profit'],
            'margin_tenths' => Money::pctTenths((int) $r['profit'], (int) $r['revenue']),
        ], $rows);

        $by = static function (string $k, bool $desc = true) use ($services): ?array {
            if (!$services) {
                return null;
            }
            $s = $services;
            usort($s, static fn(array $a, array $b): int => $desc ? $b[$k] <=> $a[$k] : $a[$k] <=> $b[$k]);
            return $s[0];
        };
        $top = $services;
        usort($top, static fn(array $a, array $b): int => [$b['orders'], $b['revenue']] <=> [$a['orders'], $a['revenue']]);

        $platforms = [];
        foreach ($services as $s) {
            $p = &$platforms[$s['platform']];
            $p ??= ['platform' => $s['platform'], 'orders' => 0, 'revenue' => 0, 'profit' => 0];
            $p['orders'] += $s['orders'];
            $p['revenue'] += $s['revenue'];
            $p['profit'] += $s['profit'];
            unset($p);
        }
        $platforms = array_values($platforms);
        usort($platforms, static fn(array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        $paidOrders = $t['orders_paid'];
        return [
            'top_services' => array_slice($top, 0, 8),
            'best_revenue' => $by('revenue'), 'best_profit' => $by('profit'), 'lowest_profit' => count($services) > 1 ? $by('profit', false) : null,
            'platforms' => $platforms, 'best_platform' => $platforms[0] ?? null,
            'auto_vs_manual' => [
                'auto' => ['orders' => $t['auto_orders'], 'revenue' => $t['auto_revenue_paise'], 'profit' => $t['auto_profit_paise']],
                'manual' => ['orders' => $t['manual_orders'], 'revenue' => $t['manual_revenue_paise'], 'profit' => $t['manual_profit_paise']],
            ],
            'aov_paise' => $paidOrders > 0 ? Money::mulDiv($t['revenue_paise'], 1, $paidOrders) : 0,
            'avg_profit_paise' => $paidOrders > 0 ? Money::mulDiv($breakdown['net'], 1, $paidOrders) : 0,
            'order_success_tenths' => Money::pctTenths($t['orders_completed'], $paidOrders),
            'orders_paid' => $paidOrders, 'orders_completed' => $t['orders_completed'],
        ];
    }

    private static function smmSpend(string $from, string $to, ?int $platformId, ?string $mode): array
    {
        [$of, $op] = Analytics::orderFilter($platformId, $mode);
        $base = "o.mode = 'AUTO' AND o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ?" . $of;
        $byProvider = Db::all(
            'SELECT o.smm_provider_id AS id, COALESCE(p.name, \'(no provider)\') AS name, COUNT(*) AS orders, SUM(o.provider_cost_paise) AS spend FROM orders o '
            . 'LEFT JOIN smm_providers p ON p.id = o.smm_provider_id WHERE ' . $base . ' GROUP BY o.smm_provider_id, p.name ORDER BY spend DESC',
            array_merge([$from, $to], $op)
        );
        $byService = Db::all(
            'SELECT o.service_id AS id, MAX(o.service_title) AS name, MAX(o.platform_slug) AS platform, COUNT(*) AS orders, SUM(o.provider_cost_paise) AS spend FROM orders o WHERE ' . $base . ' GROUP BY o.service_id ORDER BY spend DESC LIMIT 8',
            array_merge([$from, $to], $op)
        );
        $cast = static fn(array $r): array => ['id' => $r['id'] !== null ? (int) $r['id'] : null, 'name' => (string) $r['name'], 'platform' => isset($r['platform']) ? (string) $r['platform'] : null, 'orders' => (int) $r['orders'], 'spend' => (int) $r['spend']];
        return ['by_provider' => array_map($cast, $byProvider), 'by_service' => array_map($cast, $byService)];
    }

    private static function paymentStats(string $from, string $to, ?int $platformId, ?string $mode, array $t): array
    {
        [$of, $op] = Analytics::orderFilter($platformId, $mode);
        $join = $of !== '' ? ' JOIN orders o ON o.id = p.order_id' : '';
        $r = Db::one(
            "SELECT SUM(CASE WHEN p.paid_at IS NOT NULL THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN p.paid_at IS NULL AND p.status IN ('failed','mismatch') THEN 1 ELSE 0 END) AS bad, "
            . "SUM(CASE WHEN p.paid_at IS NULL AND p.status = 'expired' THEN 1 ELSE 0 END) AS expired, SUM(CASE WHEN p.paid_at IS NULL AND p.status IN ('creating','pending') THEN 1 ELSE 0 END) AS open_n "
            . 'FROM payments p' . $join . ' WHERE p.created_day BETWEEN ? AND ?' . $of,
            array_merge([$from, $to], $op)
        );
        $ok = (int) ($r['ok'] ?? 0);
        $bad = (int) ($r['bad'] ?? 0);
        $exp = (int) ($r['expired'] ?? 0);
        $final = $ok + $bad + $exp;     // sessions that reached an outcome
        return [
            'success' => $ok, 'failed' => $bad, 'expired' => $exp, 'open' => (int) ($r['open_n'] ?? 0),
            'success_tenths' => Money::pctTenths($ok, $final), 'failure_tenths' => $final > 0 ? 1000 - Money::pctTenths($ok, $final) : 0,
        ];
    }

    private static function recent(?int $platformId, ?string $mode, string $payment): array
    {
        [$of, $op] = Analytics::orderFilter($platformId, $mode);
        $payWhere = '';
        if ($payment !== '') {
            $payWhere = ' AND o.payment_status = ?';
            $op2 = array_merge($op, [$payment === 'success' ? 'success' : $payment]);
        } else {
            $op2 = $op;
        }
        $orders = Db::all(
            'SELECT o.id, o.public_id, o.service_title, o.platform_slug, o.amount_paise, o.status, o.payment_status, o.mode, o.created_at FROM orders o WHERE 1 = 1'
            . $of . $payWhere . ' ORDER BY o.id DESC LIMIT 8',
            $op2
        );
        $payStatusSql = match ($payment) {
            'success' => "AND p.status IN ('success','refunded','partially_refunded')", 'pending' => "AND p.status IN ('creating','pending')",
            'failed' => "AND p.status IN ('failed','mismatch')", 'expired' => "AND p.status = 'expired'", 'refunded' => "AND p.status IN ('refunded','partially_refunded')", default => '',
        };
        $pays = Db::all(
            'SELECT p.id, p.status, p.amount_paise, p.provider_txn_id, p.verified_via, p.late, p.created_at, p.paid_at, o.public_id, o.service_title FROM payments p JOIN orders o ON o.id = p.order_id '
            . 'WHERE 1 = 1 ' . $payStatusSql . $of . ' ORDER BY p.id DESC LIMIT 8',
            $op
        );
        $activity = Db::all('SELECT action, admin_label, entity_type, entity_id, created_at FROM audit_logs ORDER BY id DESC LIMIT 8');
        return [
            'orders' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'], 'public_id' => $r['public_id'], 'service' => $r['service_title'], 'platform' => $r['platform_slug'], 'amount_paise' => (int) $r['amount_paise'],
                'status' => $r['status'], 'status_label' => Status::orderLabel((string) $r['status']), 'payment_status' => $r['payment_status'], 'mode' => $r['mode'], 'at' => Clock::iso($r['created_at']),
            ], $orders),
            'payments' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'], 'status' => $r['status'], 'amount_paise' => (int) $r['amount_paise'], 'order' => $r['public_id'], 'service' => $r['service_title'],
                'reference' => $r['provider_txn_id'], 'via' => $r['verified_via'], 'late' => (bool) $r['late'], 'at' => Clock::iso($r['paid_at'] ?? $r['created_at']),
            ], $pays),
            'activity' => array_map(static fn(array $r): array => [
                'action' => $r['action'], 'label' => Audit::label((string) $r['action']), 'admin' => $r['admin_label'], 'entity' => $r['entity_type'] ? $r['entity_type'] . ($r['entity_id'] ? ' #' . $r['entity_id'] : '') : '', 'at' => Clock::iso($r['created_at']),
            ], $activity),
        ];
    }
}
