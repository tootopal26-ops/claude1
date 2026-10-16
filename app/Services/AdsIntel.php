<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Support\Money;
use App\Support\Tz;

/**
 * Ads Intelligence: what advertising cost, who came, who paid, and what the paying visitors were worth — per campaign and for the whole shop.
 *
 * Every figure says what it counts (DEFINITIONS, shown on the screen). The units are NOT mixed silently:
 *
 *   visit-level   visitors · qualified visitors · checkout starts   are counted in VISITOR-DAYS (one per person-cookie per business day — the same
 *                 unit as the dashboard), named after the first campaign-tagged session of that day;
 *                 sessions · page views · engaged · returning        are counted in SESSIONS, named after the session's own campaign tag;
 *   order-level   customers · orders · attributed revenue · costs    are counted from VERIFIED payments and credited by the attribution model
 *                 (last non-direct touch within the window — see Attribution), on the day the payment was verified.
 *
 * Spend: the actual spend entered for a day replaces the CPC estimate for that day (never both — the same rule the dashboard and Finance use); a campaign's
 * estimated spend is the CPC of its qualified visitor-days on days with no actual spend, so the campaign rows add up exactly to the advertising cost the
 * dashboard shows. Spend entered for a day without a campaign is shown as "(not split by campaign)": it is real, it just has no campaign to be credited to.
 *
 * "Attributed revenue" is credit under a stated model. It is NOT incremental revenue — no test of what would have happened without the advertising is run —
 * and nothing on the screens calls it that.
 */
final class AdsIntel
{
    public const NONE = '(no campaign)';
    public const DAY_TOTAL = '(not split by campaign)';
    public const CONFIDENCE_ORDERS_HIGH = 30;
    public const CONFIDENCE_ORDERS_MEDIUM = 10;

    public const DEFINITIONS = [
        'spend'              => 'Advertising cost: the actual spend entered for a day, or the CPC estimate (qualified visitors × CPC) for a day with none — never both. GST on actual spend is a cost or not, per the setting in Finance.',
        'visitors'           => 'Visitors, one per person-cookie per day — the same number as on the dashboard.',
        'qualified'          => 'Visitors who stayed the qualifying time (3 seconds by default), checked by the server. The unit the CPC estimate is billed on.',
        'sessions'           => 'Visits. A refresh stays in the same visit; a new one starts after 30 minutes idle or on a click from a different campaign. Not a count of people.',
        'page_views'         => 'Page loads inside those sessions (a refresh counts). Measured from the update that added this screen; earlier sessions show 0.',
        'engaged'            => 'Sessions that were qualified and opened a service card (the browser reports the card; it never affects spend).',
        'returning'          => 'Sessions of a visitor whose cookie had an earlier visit. A cleared cookie looks like a new visitor, so this is a lower bound.',
        'checkout_starts'    => 'Visitors (per day) who created an order — before paying.',
        'customers'          => 'Distinct visitors with a VERIFIED payment credited to the campaign. An order from a visitor the shop could not track counts as one customer.',
        'conversion'         => 'Verified customers ÷ qualified visitors of the same campaign and period. Buyers are credited by the attribution model, so it can pass 100 % when they first visited before the period.',
        'cpc'                => 'Actual spend ÷ the clicks the platform reported, for the entries that carry clicks. Empty when no clicks were entered.',
        'cost_per_qualified' => 'Advertising cost ÷ qualified visitors.',
        'cost_per_customer'  => 'Advertising cost ÷ verified customers.',
        'attributed'         => 'Net revenue (collected − refunds) of verified orders credited to the campaign by the last-non-direct-touch model within the window. Credit under a stated model — not incremental revenue.',
        'attributed_costs'   => 'The provider cost and the fulfilment, payment-fee and other costs of those orders (incomplete where a cost is not known — see Finance).',
        'contribution'       => 'Attributed revenue − attributed costs − the campaign’s advertising cost.',
    ];

    /**
     * @param array{range?:string,from?:string,to?:string,campaign?:string} $f
     * @return array<string,mixed>
     */
    public static function overview(array $f): array
    {
        $today = Tz::today();
        [$from, $to, $rangeKey] = Dashboard::resolveRange($f, $today);
        $first = min(Analytics::firstDay(), $today);
        $campaign = self::campaignKey($f['campaign'] ?? '');

        $all = self::campaigns($from, $to);
        $rows = $campaign === '' ? $all : array_values(array_filter($all, static fn(array $r): bool => $r['key'] === $campaign));
        if ($campaign !== '' && !$rows) {
            throw new HttpException(422, 'No data for that campaign in this period.', 'invalid', ['fields' => ['campaign' => 'No data for that campaign in this period.']]);
        }
        $t = self::totals($rows);
        $customers = self::customersIn($from, $to, $campaign);
        $span = Tz::daysBetween($from, $to) + 1;

        $spend = static fn(string $a, string $b): array => self::spendBlock(self::spendByCampaign($a, $b), $campaign, $a, $b);
        $used = $t['spend_actual'] + $t['spend_estimated'];
        $q = $t['qualified_visitors'];
        $contribution = $t['attributed_revenue'] - $t['attributed_costs'];
        $cov = self::coverage($from, $to);

        return [
            'meta' => [
                'generated_at' => gmdate('c'), 'today' => $today, 'timezone_label' => Tz::label(),
                'range' => ['key' => $rangeKey, 'from' => $from, 'to' => $to, 'days' => $span], 'campaign' => $campaign,
                'campaigns' => array_values(array_map(static fn(array $r): array => ['key' => $r['key'], 'label' => $r['label']], $all)),
                'attribution' => ['model' => Attribution::MODEL, 'label' => Attribution::MODEL_LABEL, 'window_days' => Attribution::windowDays()],
                'cpc_paise' => Ads::cpcPaise(), 'qualify_seconds' => Ads::current()['qualify_seconds'], 'ad_gst' => Finance::settings()['ad_gst'], 'first_day' => $first,
            ],
            'definitions' => self::DEFINITIONS,
            'cards' => [
                'spend_today' => $spend($today, $today), 'spend_lifetime' => $spend($first, $today), 'spend_period' => $spend($from, $to),
                'visitors' => $t['visitors'], 'qualified' => $q, 'sessions' => $t['sessions'], 'page_views' => $t['page_views'], 'engaged_sessions' => $t['engaged'], 'returning_sessions' => $t['returning'],
                'checkout_starts' => $t['checkout_starts'], 'customers' => $customers, 'orders' => $t['orders'],
                'conversion_tenths' => $q > 0 ? Money::pctTenths($customers, $q) : null,
                'cpc' => $t['clicks'] !== null && $t['clicks'] > 0 && $t['spend_with_clicks'] > 0 ? Money::mulDiv($t['spend_with_clicks'], 1, $t['clicks']) : null,
                'cost_per_customer' => $customers > 0 && $used > 0 ? Money::mulDiv($used, 1, $customers) : null,
                'cost_per_qualified' => $q > 0 && $used > 0 ? Money::mulDiv($used, 1, $q) : null,
                'attributed_revenue' => $t['attributed_revenue'], 'attributed_refunds' => $t['attributed_refunds'], 'attributed_costs' => $t['attributed_costs'],
                'contribution_after_ads' => $contribution - $used, 'advertising_used' => $used,
                'confidence' => self::confidence($t['actual_rows'] > 0, $t['spend_estimated'] > 0, $used, $t['orders']),
            ],
            'campaigns' => $rows,
            'trend' => self::trend($from, $to, $campaign),
            'coverage' => $cov,
            'notes' => self::notes($t, $span, $cov),
        ];
    }

    // ═══════════════════════════ spend ══════════════════════════════════════

    /**
     * Spend facts per campaign key for a period: the ACTUAL spend entered (GST per the setting), its pre-tax part and the clicks reported, and the
     * ESTIMATED spend — the CPC of qualified visitor-days on days that have no actual spend at all.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function spendByCampaign(string $from, string $to): array
    {
        $gst = Finance::settings()['ad_gst'] === 'cost' ? ' + a.gst_paise' : '';
        $out = [];
        $slot = static function (string $k) use (&$out): void {
            $out[$k] ??= ['actual' => 0, 'pretax' => 0, 'with_clicks' => 0, 'clicks' => null, 'impressions' => null, 'rows' => 0, 'estimated' => 0];
        };
        foreach (Db::all(
            'SELECT a.campaign AS c, SUM(a.amount_paise' . $gst . ') AS s, SUM(a.amount_paise) AS p, SUM(CASE WHEN a.clicks IS NOT NULL THEN a.amount_paise ELSE 0 END) AS wc, '
            . 'SUM(a.clicks) AS cl, SUM(a.impressions) AS im, COUNT(*) AS n FROM ad_daily_spend a WHERE a.voided_at IS NULL AND a.spend_day BETWEEN ? AND ? GROUP BY a.campaign',
            [$from, $to]
        ) as $r) {
            $k = (string) $r['c'] === '' ? self::DAY_TOTAL : (string) $r['c'];
            $slot($k);
            $out[$k]['actual'] += (int) $r['s'];
            $out[$k]['pretax'] += (int) $r['p'];
            $out[$k]['with_clicks'] += (int) $r['wc'];
            $out[$k]['clicks'] = $r['cl'] !== null ? (int) $r['cl'] : $out[$k]['clicks'];
            $out[$k]['impressions'] = $r['im'] !== null ? (int) $r['im'] : $out[$k]['impressions'];
            $out[$k]['rows'] += (int) $r['n'];
        }
        foreach (Db::all(
            'SELECT vd.utm_campaign AS c, SUM(vd.cpc_paise) AS s FROM visitor_days vd WHERE vd.qualified = 1 AND vd.day BETWEEN ? AND ? '
            . 'AND NOT EXISTS (SELECT 1 FROM ad_daily_spend a WHERE a.spend_day = vd.day AND a.voided_at IS NULL) GROUP BY vd.utm_campaign',
            [$from, $to]
        ) as $r) {
            $k = self::key($r['c']);
            $slot($k);
            $out[$k]['estimated'] += (int) $r['s'];
        }
        return $out;
    }

    /**
     * The advertising cost of a period for one campaign (or the whole shop), and where it comes from.
     *
     * @param array<string,array<string,mixed>> $by spendByCampaign()
     * @return array<string,mixed>
     */
    private static function spendBlock(array $by, string $campaign, string $from, string $to): array
    {
        $sel = $campaign === '' ? $by : array_intersect_key($by, [$campaign => true]);
        $actual = array_sum(array_column($sel, 'actual'));
        $est = array_sum(array_column($sel, 'estimated'));
        $rows = array_sum(array_column($sel, 'rows'));
        $source = $rows > 0 && $est > 0 ? 'mixed' : ($rows > 0 ? 'actual' : ($est > 0 ? 'estimated' : 'none'));
        $actualDays = (int) Db::val('SELECT COUNT(DISTINCT spend_day) FROM ad_daily_spend WHERE voided_at IS NULL AND spend_day BETWEEN ? AND ?', [$from, $to]);
        return ['paise' => $actual + $est, 'actual_paise' => $actual, 'estimated_paise' => $est, 'actual_days' => $actualDays, 'days' => Tz::daysBetween($from, $to) + 1, 'source' => $source];
    }

    // ═══════════════════════════ campaigns ══════════════════════════════════

    /**
     * One row per campaign: traffic, spend, attributed orders and the ratios that are valid for it.
     *
     * @return list<array<string,mixed>>
     */
    public static function campaigns(string $from, string $to): array
    {
        $rows = [];
        $row = static function (string $key) use (&$rows): void {
            $rows[$key] ??= [
                'key' => $key, 'label' => $key, 'visitors' => 0, 'qualified_visitors' => 0, 'checkout_starts' => 0,
                'sessions' => 0, 'page_views' => 0, 'qualified_sessions' => 0, 'engaged' => 0, 'returning' => 0,
                'customers' => 0, 'orders' => 0, 'attributed_revenue' => 0, 'attributed_refunds' => 0, 'attributed_costs' => 0,
                'spend_actual' => 0, 'spend_estimated' => 0, 'actual_rows' => 0, 'spend_with_clicks' => 0, 'clicks' => null, 'impressions' => null,
            ];
        };

        // visitor-days: the unit the CPC is billed on and the dashboard counts visitors in
        foreach (Db::all(
            'SELECT vd.utm_campaign AS c, COUNT(*) AS v, SUM(vd.qualified) AS q, SUM(vd.order_created) AS oc FROM visitor_days vd WHERE vd.day BETWEEN ? AND ? GROUP BY vd.utm_campaign',
            [$from, $to]
        ) as $r) {
            $k = self::key($r['c']);
            $row($k);
            $rows[$k]['visitors'] += (int) $r['v'];
            $rows[$k]['qualified_visitors'] += (int) $r['q'];
            $rows[$k]['checkout_starts'] += (int) $r['oc'];
        }
        // sessions: visits and what they did
        foreach (Db::all(
            'SELECT s.utm_campaign AS c, COUNT(*) AS n, SUM(s.page_views) AS pv, SUM(CASE WHEN s.qualified_at IS NOT NULL THEN 1 ELSE 0 END) AS q, '
            . 'SUM(CASE WHEN s.engaged_at IS NOT NULL THEN 1 ELSE 0 END) AS e, SUM(s.returning_visitor) AS rt FROM visitor_sessions s WHERE s.day BETWEEN ? AND ? GROUP BY s.utm_campaign',
            [$from, $to]
        ) as $r) {
            $k = self::key($r['c']);
            $row($k);
            $rows[$k]['sessions'] += (int) $r['n'];
            $rows[$k]['page_views'] += (int) $r['pv'];
            $rows[$k]['qualified_sessions'] += (int) $r['q'];
            $rows[$k]['engaged'] += (int) $r['e'];
            $rows[$k]['returning'] += (int) $r['rt'];
        }
        // spend (actual and estimated)
        foreach (self::spendByCampaign($from, $to) as $k => $s) {
            $row($k);
            $rows[$k]['spend_actual'] += $s['actual'];
            $rows[$k]['spend_estimated'] += $s['estimated'];
            $rows[$k]['actual_rows'] += $s['rows'];
            $rows[$k]['spend_with_clicks'] += $s['with_clicks'];
            $rows[$k]['clicks'] = $s['clicks'] ?? $rows[$k]['clicks'];
            $rows[$k]['impressions'] = $s['impressions'] ?? $rows[$k]['impressions'];
        }
        // verified orders credited to the campaign (by the day the payment was verified)
        foreach (Db::all(
            'SELECT ca.utm_campaign AS c, COUNT(*) AS n, COUNT(DISTINCT COALESCE(CONCAT(\'v\', o.visitor_id), CONCAT(\'o\', o.id))) AS cust, '
            . 'SUM(o.amount_paise - o.refunded_paise) AS rev, SUM(o.refunded_paise) AS ref, '
            . "SUM((CASE WHEN o.mode = 'AUTO' THEN o.provider_cost_paise ELSE 0 END) + " . Finance::linesSql('o') . ') AS costs '
            . 'FROM campaign_attribution ca JOIN orders o ON o.id = ca.order_id WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ? GROUP BY ca.utm_campaign',
            [$from, $to]
        ) as $r) {
            $k = self::key($r['c']);
            $row($k);
            $rows[$k]['orders'] += (int) $r['n'];
            $rows[$k]['customers'] += (int) $r['cust'];
            $rows[$k]['attributed_revenue'] += (int) $r['rev'];
            $rows[$k]['attributed_refunds'] += (int) $r['ref'];
            $rows[$k]['attributed_costs'] += (int) $r['costs'];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = self::finish($r);
        }
        usort($out, static fn(array $a, array $b): int => [$b['spend_used'], $b['qualified_visitors'], $b['sessions'], $a['key']] <=> [$a['spend_used'], $a['qualified_visitors'], $a['sessions'], $b['key']]);
        return $out;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function finish(array $r): array
    {
        $used = $r['spend_actual'] + $r['spend_estimated'];
        $hasActual = $r['actual_rows'] > 0;
        $source = $hasActual && $r['spend_estimated'] > 0 ? 'mixed' : ($hasActual ? 'actual' : ($r['spend_estimated'] > 0 ? 'estimated' : 'none'));
        $q = $r['qualified_visitors'];
        $c = $r['customers'];
        $contribution = $r['attributed_revenue'] - $r['attributed_costs'];
        return $r + [
            'spend_used' => $used, 'spend_source' => $source,
            'cpc' => $r['clicks'] !== null && $r['clicks'] > 0 && $r['spend_with_clicks'] > 0 ? Money::mulDiv($r['spend_with_clicks'], 1, $r['clicks']) : null,
            'cost_per_qualified' => $q > 0 && $used > 0 ? Money::mulDiv($used, 1, $q) : null,
            'cost_per_customer' => $c > 0 && $used > 0 ? Money::mulDiv($used, 1, $c) : null,
            'conversion_tenths' => $q > 0 ? Money::pctTenths($c, $q) : null,
            'attributed_contribution' => $contribution, 'contribution_after_ads' => $contribution - $used,
            'confidence' => self::confidence($hasActual, $r['spend_estimated'] > 0, $used, $r['orders']),
        ];
    }

    /**
     * How far a campaign's ratios can be trusted — and the reason in words. Actual spend and a decent number of attributed orders are what earn it.
     *
     * @return array{level:string,why:string}
     */
    private static function confidence(bool $hasActual, bool $hasEstimate, int $spend, int $orders): array
    {
        $n = $orders . ' attributed order' . ($orders === 1 ? '' : 's');
        if ($hasActual && !$hasEstimate && $orders >= self::CONFIDENCE_ORDERS_HIGH) {
            return ['level' => 'high', 'why' => 'Actual spend entered and ' . $n . '.'];
        }
        if ($hasActual && $orders >= self::CONFIDENCE_ORDERS_MEDIUM) {
            return ['level' => 'medium', 'why' => ($hasEstimate ? 'Part of the spend is a CPC estimate; ' : 'Actual spend entered; ') . $n . '.'];
        }
        if (!$hasActual) {
            return ['level' => 'low', 'why' => $spend > 0 ? 'The spend is a CPC estimate, not billed spend.' : 'No spend recorded.'];
        }
        return ['level' => 'low', 'why' => 'Actual spend entered, but only ' . $n . ' — too few to rely on a ratio.'];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function totals(array $rows): array
    {
        $t = [
            'visitors' => 0, 'qualified_visitors' => 0, 'checkout_starts' => 0, 'sessions' => 0, 'page_views' => 0, 'engaged' => 0, 'returning' => 0,
            'orders' => 0, 'attributed_revenue' => 0, 'attributed_refunds' => 0, 'attributed_costs' => 0, 'spend_actual' => 0, 'spend_estimated' => 0, 'actual_rows' => 0,
            'spend_with_clicks' => 0, 'clicks' => null,
        ];
        foreach ($rows as $r) {
            foreach (array_keys($t) as $k) {
                if ($k === 'clicks') {
                    $t['clicks'] = $r['clicks'] !== null ? ($t['clicks'] ?? 0) + $r['clicks'] : $t['clicks'];
                } else {
                    $t[$k] += $r[$k];
                }
            }
        }
        return $t;
    }

    /** Distinct customers of a period (or of one campaign): a visitor who bought through two campaigns is one customer in the total, and in each row. */
    private static function customersIn(string $from, string $to, string $campaign): int
    {
        $cond = '';
        $args = [$from, $to];
        if ($campaign !== '') {
            $cond = $campaign === self::NONE ? ' AND ca.utm_campaign IS NULL' : ' AND ca.utm_campaign = ?';
            if ($campaign !== self::NONE) {
                $args[] = $campaign;
            }
        }
        return (int) Db::val(
            'SELECT COUNT(DISTINCT COALESCE(CONCAT(\'v\', o.visitor_id), CONCAT(\'o\', o.id))) FROM campaign_attribution ca JOIN orders o ON o.id = ca.order_id '
            . 'WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ?' . $cond,
            $args
        );
    }

    /**
     * What the screen cannot say because the data is not there — named, never guessed.
     *
     * @return array{unattributed_orders:int,unattributed_revenue:int,untracked_orders:int,unmeasured_sessions:int}
     */
    public static function coverage(string $from, string $to): array
    {
        $u = Db::one(
            'SELECT COUNT(*) AS n, COALESCE(SUM(o.amount_paise - o.refunded_paise), 0) AS rev FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ? '
            . 'AND NOT EXISTS (SELECT 1 FROM campaign_attribution ca WHERE ca.order_id = o.id)',
            [$from, $to]
        ) ?? ['n' => 0, 'rev' => 0];
        return [
            'unattributed_orders' => (int) $u['n'], 'unattributed_revenue' => (int) $u['rev'],
            'untracked_orders' => (int) Db::val(
                'SELECT COUNT(*) FROM campaign_attribution ca JOIN orders o ON o.id = ca.order_id WHERE o.paid_at IS NOT NULL AND o.visitor_id IS NULL AND o.paid_day BETWEEN ? AND ?',
                [$from, $to]
            ),
            'unmeasured_sessions' => (int) Db::val('SELECT COUNT(*) FROM visitor_sessions WHERE page_views = 0 AND day BETWEEN ? AND ?', [$from, $to]),
        ];
    }

    /**
     * @param array<string,mixed> $t totals
     * @param array<string,int> $cov coverage()
     * @return list<string>
     */
    private static function notes(array $t, int $span, array $cov): array
    {
        $n = [];
        $used = $t['spend_actual'] + $t['spend_estimated'];
        if ($used > 0 || $t['qualified_visitors'] > 0) {
            if ($t['actual_rows'] === 0) {
                $n[] = 'All advertising cost in this period is the CPC estimate (qualified visitors × CPC), not billed spend. Enter the actual spend under Spend to replace it.';
            } elseif ($t['spend_estimated'] > 0) {
                $n[] = 'Some days have actual spend entered; the others use the CPC estimate. A day with actual spend never also carries the estimate — so enter every campaign of a day (or the day’s total).';
            }
        }
        $n[] = 'Attributed revenue is credit under the ' . Attribution::MODEL_LABEL . ' model (' . Attribution::windowDays() . '-day window), not incremental revenue: no test of what would have happened without the advertising is run.';
        if ($cov['unattributed_orders'] > 0) {
            $n[] = $cov['unattributed_orders'] . ' verified order' . ($cov['unattributed_orders'] === 1 ? ' was' : 's were') . ' paid before attribution was recorded (or it was missed) and ' . ($cov['unattributed_orders'] === 1 ? 'is' : 'are') . ' not credited to any campaign below — attribute them from Settings.';
        }
        if ($cov['untracked_orders'] > 0) {
            $n[] = $cov['untracked_orders'] . ' verified order' . ($cov['untracked_orders'] === 1 ? ' came' : 's came') . ' from visitors the shop could not track (cookies blocked); each counts as one customer, under “' . self::NONE . '”.';
        }
        if ($cov['unmeasured_sessions'] > 0) {
            $n[] = $cov['unmeasured_sessions'] . ' session' . ($cov['unmeasured_sessions'] === 1 ? '' : 's') . ' in this period were recorded before page views were measured; they count as 0 page views.';
        }
        return $n;
    }

    /** Campaign names are lower-case words; '' = all, the two labels the table itself uses are accepted back. */
    private static function campaignKey(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '' || $v === self::NONE || $v === self::DAY_TOTAL) {
            return $v;
        }
        return AdSpend::clean($v);
    }

    private static function key(mixed $campaign): string
    {
        return $campaign === null || $campaign === '' ? self::NONE : (string) $campaign;
    }

    // ═══════════════════════════ trend ══════════════════════════════════════

    /**
     * Per day (or week / month for long ranges): spend used, qualified visitors, verified orders and attributed revenue — for the whole shop, or for one campaign.
     *
     * @return list<array<string,mixed>>
     */
    private static function trend(string $from, string $to, string $campaign): array
    {
        $span = Tz::daysBetween($from, $to);
        $bucket = $span <= 120 ? 'day' : ($span <= 800 ? 'week' : 'month');
        $gst = Finance::settings()['ad_gst'] === 'cost' ? ' + a.gst_paise' : '';

        // attributed orders by the day the payment was verified
        $campCond = '';
        $args = [$from, $to];
        if ($campaign !== '') {
            $campCond = $campaign === self::NONE ? ' AND ca.utm_campaign IS NULL' : ' AND ca.utm_campaign = ?';
            if ($campaign !== self::NONE) {
                $args[] = $campaign;
            }
        }
        $attr = [];
        foreach (Db::all(
            'SELECT o.paid_day AS d, COUNT(*) AS n, SUM(o.amount_paise - o.refunded_paise) AS rev FROM campaign_attribution ca JOIN orders o ON o.id = ca.order_id '
            . 'WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ?' . $campCond . ' GROUP BY o.paid_day',
            $args
        ) as $r) {
            $attr[(string) $r['d']] = ['n' => (int) $r['n'], 'rev' => (int) $r['rev']];
        }

        // qualified visitors and the CPC estimate by day (for a campaign: its own visitor-days)
        $vcond = '';
        $vargs = [$from, $to];
        if ($campaign !== '' && $campaign !== self::DAY_TOTAL) {
            $vcond = $campaign === self::NONE ? ' AND vd.utm_campaign IS NULL' : ' AND vd.utm_campaign = ?';
            if ($campaign !== self::NONE) {
                $vargs[] = $campaign;
            }
        } elseif ($campaign === self::DAY_TOTAL) {
            $vcond = ' AND 1 = 0';                                  // spend without a campaign has no visitors of its own
        }
        $vis = [];
        foreach (Db::all(
            'SELECT vd.day AS d, SUM(vd.qualified) AS q, SUM(CASE WHEN vd.qualified = 1 AND NOT EXISTS (SELECT 1 FROM ad_daily_spend a WHERE a.spend_day = vd.day AND a.voided_at IS NULL) THEN vd.cpc_paise ELSE 0 END) AS est '
            . 'FROM visitor_days vd WHERE vd.day BETWEEN ? AND ?' . $vcond . ' GROUP BY vd.day',
            $vargs
        ) as $r) {
            $vis[(string) $r['d']] = ['q' => (int) $r['q'], 'est' => (int) $r['est']];
        }

        // actual spend by day
        $scond = '';
        $sargs = [$from, $to];
        if ($campaign !== '') {
            if ($campaign === self::NONE) {
                $scond = ' AND 1 = 0';                               // actual spend always names a campaign or "not split"
            } elseif ($campaign === self::DAY_TOTAL) {
                $scond = " AND a.campaign = ''";
            } else {
                $scond = ' AND a.campaign = ?';
                $sargs[] = $campaign;
            }
        }
        $act = [];
        foreach (Db::all('SELECT a.spend_day AS d, SUM(a.amount_paise' . $gst . ') AS s FROM ad_daily_spend a WHERE a.voided_at IS NULL AND a.spend_day BETWEEN ? AND ?' . $scond . ' GROUP BY a.spend_day', $sargs) as $r) {
            $act[(string) $r['d']] = (int) $r['s'];
        }

        $groups = [];
        for ($day = $from; $day <= $to; $day = Tz::addDays($day, 1)) {
            $key = match ($bucket) {
                'week'  => (new \DateTimeImmutable($day))->modify('monday this week')->format('Y-m-d'),
                'month' => substr($day, 0, 7) . '-01',
                default => $day,
            };
            $g = &$groups[$key];
            $g ??= ['key' => $key, 'spend' => 0, 'actual' => 0, 'qualified' => 0, 'orders' => 0, 'revenue' => 0];
            $g['actual'] += $act[$day] ?? 0;
            $g['spend'] += ($act[$day] ?? 0) + ($vis[$day]['est'] ?? 0);
            $g['qualified'] += $vis[$day]['q'] ?? 0;
            $g['orders'] += $attr[$day]['n'] ?? 0;
            $g['revenue'] += $attr[$day]['rev'] ?? 0;
            unset($g);
        }
        return array_values($groups);
    }
}
