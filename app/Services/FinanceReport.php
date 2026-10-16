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
 * The Finance screen's numbers: an overview for a date range (with its labels and definitions), the profitability of every service, and the
 * reconciliation view — the list of records whose money is incomplete or inconsistent, with what to do about each.
 *
 * Every figure comes from Analytics::computeDays / the orders table, so it is traceable to rows; nothing here is stored separately.
 */
final class FinanceReport
{
    // ═══════════════════════════ overview ═══════════════════════════════════

    /**
     * @param array{range?:string,from?:string,to?:string,platform?:string,service?:string,mode?:string,payment?:string} $f
     * @return array<string,mixed>
     */
    public static function overview(array $f): array
    {
        $today = Tz::today();
        [$from, $to, $rangeKey] = Dashboard::resolveRange($f, $today);
        [$platformId, $platformSlug] = self::platform($f);
        [$serviceId, $serviceCode] = self::service($f);
        $mode = in_array($f['mode'] ?? '', ['AUTO', 'MANUAL'], true) ? (string) $f['mode'] : null;
        $payment = in_array($f['payment'] ?? '', ['success', 'pending', 'failed', 'expired', 'refunded'], true) ? (string) $f['payment'] : '';
        $filtered = $platformId !== null || $serviceId !== null || $mode !== null;

        $period = $filtered ? Analytics::computeDays($from, $to, $platformId, $mode, $serviceId) : Analytics::rollup($from, $to);
        $todayDays = $filtered ? Analytics::computeDays($today, $today, $platformId, $mode, $serviceId) : Analytics::rollup($today, $today);
        $first = min(Analytics::firstDay(), $today);
        $life = $filtered ? Analytics::computeDays($first, $today, $platformId, $mode, $serviceId) : Analytics::rollup($first, $today);
        if ($filtered) {
            // advertising, chargebacks and business expenses belong to the whole business, not to a platform / service / order type: net = contribution
            foreach ([&$period, &$todayDays, &$life] as &$set) {
                foreach ($set as &$m) {
                    $m['chargebacks_paise'] = 0;
                    $m['expenses_paise'] = 0;
                    $m['net_profit_paise'] = $m['gross_profit_paise'];
                }
                unset($m);
            }
            unset($set);
        }

        $bucket = $from === $to ? 'day' : (Tz::daysBetween($from, $to) <= 120 ? 'day' : (Tz::daysBetween($from, $to) <= 800 ? 'week' : 'month'));
        $totals = Analytics::sum($period);
        $settings = Finance::settings();
        return [
            'meta' => [
                'generated_at' => gmdate('c'), 'today' => $today, 'timezone' => Tz::name(), 'timezone_label' => Tz::label(),
                'range' => ['key' => $rangeKey, 'from' => $from, 'to' => $to, 'bucket' => $bucket, 'days' => Tz::daysBetween($from, $to) + 1],
                'filters' => ['platform' => $platformSlug, 'service' => $serviceCode, 'mode' => $mode ?? '', 'payment' => $payment], 'filtered' => $filtered,
                'shared_costs_included' => !$filtered,
                'platforms' => array_map(static fn(array $p): array => ['slug' => $p['slug'], 'name' => $p['name']], Catalog::platforms(false)),
                'services' => array_map(static fn(array $s): array => ['code' => $s['code'], 'title' => $s['title']], Db::all('SELECT code, title FROM services WHERE deleted_at IS NULL ORDER BY platform_id, sort_order, id')),
                'first_day' => $first, 'fee' => ['mode' => $settings['fee_mode'], 'description' => Finance::feeDescription($settings)], 'ad_gst' => $settings['ad_gst'],
            ],
            'definitions' => Finance::DEFINITIONS,
            'today' => self::metrics(Analytics::sum($todayDays)),
            'period' => self::metrics($totals),
            'lifetime' => self::metrics(Analytics::sum($life)),
            'trend' => self::series($period, $bucket),
            'labels' => self::labels($totals, $filtered, $period),
            'payments' => self::paymentCounts($from, $to, $platformId, $mode, $serviceId, $payment),
            'services' => self::services($from, $to, $platformId, $mode, $serviceId),
        ];
    }

    /**
     * The headline figures of a set of days, as integer paise / counts. Margin is in tenths of a percent, null when net revenue is not above zero.
     *
     * @param array<string,int> $t Analytics::sum()
     * @return array<string,mixed>
     */
    public static function metrics(array $t): array
    {
        $collected = $t['revenue_paise'] + $t['refunds_paise'];
        $net = $collected - $t['refunds_paise'] - $t['chargebacks_paise'];
        $costs = $t['smm_spend_paise'] + $t['fulfil_cost_paise'] + $t['payment_fee_paise'] + $t['other_cost_paise'];
        $paid = $t['orders_paid'];
        return [
            'collected' => $collected, 'refunds' => $t['refunds_paise'], 'chargebacks' => $t['chargebacks_paise'], 'net_revenue' => $net,
            'provider_cost' => $t['smm_spend_paise'], 'fulfilment' => $t['fulfil_cost_paise'], 'payment_fees' => $t['payment_fee_paise'], 'other_costs' => $t['other_cost_paise'],
            'order_costs' => $costs,
            'contribution' => $t['gross_profit_paise'],
            'advertising' => $t['ads_spend_paise'], 'ads_actual' => $t['ads_actual_paise'], 'ads_estimated' => $t['ads_estimated_paise'], 'ads_actual_days' => $t['ads_actual_day'],
            'expenses' => $t['expenses_paise'],
            'net_profit' => $t['net_profit_paise'],
            'margin_tenths' => $net > 0 ? Money::pctTenths($t['net_profit_paise'], $net) : null,
            'orders_paid' => $paid, 'orders_created' => $t['orders_created'], 'orders_completed' => $t['orders_completed'],
            'aov' => $paid > 0 ? Money::mulDiv($collected, 1, $paid) : null,
            'payments_success' => $t['payments_success'], 'payments_failed' => $t['payments_failed'], 'payments_expired' => $t['payments_expired'],
            'orders_estimated' => $t['orders_cost_estimated'], 'orders_incomplete' => $t['orders_cost_incomplete'],
        ];
    }

    /**
     * How trustworthy the profit of a period is, and exactly why.
     *
     * @param array<string,int> $t the summed period
     * @param array<string,array<string,int>> $days the period's days (advertising is judged day by day)
     * @return array{status:string,reasons:list<string>,orders:array{actual:int,estimated:int,incomplete:int},ads:string}
     */
    public static function labels(array $t, bool $filtered, array $days = []): array
    {
        $paid = $t['orders_paid'];
        $inc = $t['orders_cost_incomplete'];
        $est = $t['orders_cost_estimated'];
        $reasons = [];
        if ($inc > 0) {
            $reasons[] = $inc . ' of ' . $paid . ' paid order' . ($paid === 1 ? '' : 's') . ' ' . ($inc === 1 ? 'has' : 'have') . ' a cost that is not known yet (see Reconciliation) — profit is incomplete.';
        }
        if ($est > 0) {
            $reasons[] = $est . ' paid order' . ($est === 1 ? '' : 's') . ' ' . ($est === 1 ? 'uses' : 'use') . ' an estimated cost (a service’s standard cost, the fee model, or a provider charge not yet final).';
        }
        $actualDays = 0;
        $estimateDays = 0;
        foreach ($days as $m) {
            if ($m['ads_actual_day'] > 0) {
                $actualDays++;
            } elseif ($m['ads_estimated_paise'] > 0) {
                $estimateDays++;
            }
        }
        $adsLabel = $actualDays === 0 && $estimateDays === 0 ? 'none' : ($estimateDays === 0 ? 'actual' : ($actualDays === 0 ? 'estimated' : 'mixed'));
        if (!$filtered) {
            if ($adsLabel === 'estimated') {
                $reasons[] = 'Advertising is the CPC estimate (qualified visitors × CPC), not billed spend. Enter the actual spend under Ads Intelligence.';
            } elseif ($adsLabel === 'mixed') {
                $reasons[] = 'Advertising is the actual spend for ' . $actualDays . ' day' . ($actualDays === 1 ? '' : 's') . ' and the CPC estimate for ' . $estimateDays . '.';
            }
        } else {
            $reasons[] = 'Filtered view: advertising, chargebacks and business expenses belong to the whole business and are not included.';
        }
        $adsCounts = !$filtered && in_array($adsLabel, ['estimated', 'mixed'], true);
        $status = $inc > 0 ? Finance::STATUS_INCOMPLETE : ($est > 0 || $adsCounts ? Finance::STATUS_ESTIMATED : Finance::STATUS_ACTUAL);
        return ['status' => $status, 'reasons' => $reasons, 'orders' => ['actual' => max(0, $paid - $inc - $est), 'estimated' => $est, 'incomplete' => $inc], 'ads' => $adsLabel, 'ads_days' => ['actual' => $actualDays, 'estimated' => $estimateDays]];
    }

    /**
     * @param array<string,array<string,int>> $days
     * @return list<array<string,mixed>>
     */
    private static function series(array $days, string $bucket): array
    {
        $groups = [];
        foreach ($days as $day => $m) {
            $key = match ($bucket) {
                'week'  => (new \DateTimeImmutable($day))->modify('monday this week')->format('Y-m-d'),
                'month' => substr($day, 0, 7) . '-01',
                default => $day,
            };
            $groups[$key][] = $m;
        }
        $out = [];
        foreach ($groups as $key => $list) {
            $t = Analytics::sum($list);
            $x = self::metrics($t);
            $out[] = [
                'key' => $key, 'collected' => $x['collected'], 'net_revenue' => $x['net_revenue'], 'contribution' => $x['contribution'], 'net_profit' => $x['net_profit'],
                'order_costs' => $x['order_costs'], 'advertising' => $x['advertising'], 'expenses' => $x['expenses'] + $x['chargebacks'], 'orders' => $x['orders_paid'],
            ];
        }
        return $out;
    }

    /** @return array{success:int,pending:int,failed:int,expired:int,refunded:int,filter:string} payments CREATED in the range, by outcome */
    private static function paymentCounts(string $from, string $to, ?int $platformId, ?string $mode, ?int $serviceId, string $payment): array
    {
        [$of, $op] = Analytics::orderFilter($platformId, $mode, $serviceId);
        $r = Db::one(
            "SELECT SUM(CASE WHEN p.paid_at IS NOT NULL THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN p.paid_at IS NULL AND p.status IN ('creating','pending') THEN 1 ELSE 0 END) AS pend, "
            . "SUM(CASE WHEN p.paid_at IS NULL AND p.status IN ('failed','mismatch') THEN 1 ELSE 0 END) AS bad, SUM(CASE WHEN p.paid_at IS NULL AND p.status = 'expired' THEN 1 ELSE 0 END) AS expired, "
            . "SUM(CASE WHEN p.status IN ('refunded','partially_refunded') THEN 1 ELSE 0 END) AS refunded FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.created_day BETWEEN ? AND ?" . $of,
            array_merge([$from, $to], $op)
        ) ?? [];
        return ['success' => (int) ($r['ok'] ?? 0), 'pending' => (int) ($r['pend'] ?? 0), 'failed' => (int) ($r['bad'] ?? 0), 'expired' => (int) ($r['expired'] ?? 0), 'refunded' => (int) ($r['refunded'] ?? 0), 'filter' => $payment];
    }

    /** @return array{0:?int,1:string} */
    private static function platform(array $f): array
    {
        $slug = (string) ($f['platform'] ?? '');
        if ($slug === '') {
            return [null, ''];
        }
        $id = Db::val('SELECT id FROM platforms WHERE slug = ?', [$slug]);
        if ($id === null) {
            throw new HttpException(422, 'Unknown platform.', 'invalid', ['fields' => ['platform' => 'Unknown platform.']]);
        }
        return [(int) $id, $slug];
    }

    /** @return array{0:?int,1:string} */
    private static function service(array $f): array
    {
        $code = (string) ($f['service'] ?? '');
        if ($code === '') {
            return [null, ''];
        }
        $id = Db::val('SELECT id FROM services WHERE code = ?', [$code]);
        if ($id === null) {
            throw new HttpException(422, 'Unknown service.', 'invalid', ['fields' => ['service' => 'Unknown service.']]);
        }
        return [(int) $id, $code];
    }

    // ═══════════════════════════ services ═══════════════════════════════════

    /**
     * Every service that sold in the range, with its contribution and how complete it is — and the services whose cost is not set up at all.
     *
     * @return array{rows:list<array<string,mixed>>,most_profitable:list<array<string,mixed>>,loss_making:list<array<string,mixed>>,missing_cost:list<array<string,mixed>>}
     */
    public static function services(string $from, string $to, ?int $platformId = null, ?string $mode = null, ?int $serviceId = null): array
    {
        [$of, $op] = Analytics::orderFilter($platformId, $mode, $serviceId);
        $inc = Finance::incompleteSql('o');
        $rows = Db::all(
            'SELECT o.service_id AS id, MAX(o.service_code) AS code, MAX(o.service_title) AS title, MAX(o.platform_slug) AS platform, MAX(o.mode) AS mode, COUNT(*) AS orders, '
            . 'SUM(o.amount_paise) AS collected, SUM(o.refunded_paise) AS refunds, SUM(CASE WHEN o.mode = \'AUTO\' THEN o.provider_cost_paise ELSE 0 END) AS provider, '
            . 'SUM(' . Finance::linesSql('o') . ') AS line_sum, SUM(CASE WHEN ' . $inc . ' THEN 1 ELSE 0 END) AS inc, '
            . 'SUM(CASE WHEN NOT ' . $inc . ' AND ' . Finance::estimatedSql('o') . ' THEN 1 ELSE 0 END) AS est '
            . 'FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ?' . $of . ' GROUP BY o.service_id',
            array_merge([$from, $to], $op)
        );
        $conf = [];
        foreach (Db::all('SELECT id, code, title, mode, smm_cost_paise, fulfil_cost_paise, status FROM services WHERE deleted_at IS NULL') as $s) {
            $conf[(int) $s['id']] = $s;
        }
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $seen[$id] = true;
            $collected = (int) $r['collected'];
            $refunds = (int) $r['refunds'];
            $costs = (int) $r['provider'] + (int) $r['line_sum'];
            $contribution = $collected - $refunds - $costs;
            $netRev = $collected - $refunds;
            $out[] = [
                'id' => $id, 'code' => (string) $r['code'], 'title' => (string) $r['title'], 'platform' => (string) $r['platform'], 'mode' => (string) $r['mode'], 'orders' => (int) $r['orders'],
                'collected' => $collected, 'refunds' => $refunds, 'provider_cost' => (int) $r['provider'], 'line_costs' => (int) $r['line_sum'], 'contribution' => $contribution,
                'margin_tenths' => $netRev > 0 ? Money::pctTenths($contribution, $netRev) : null,
                'incomplete' => (int) $r['inc'], 'estimated' => (int) $r['est'],
                'status' => (int) $r['inc'] > 0 ? Finance::STATUS_INCOMPLETE : ((int) $r['est'] > 0 ? Finance::STATUS_ESTIMATED : Finance::STATUS_ACTUAL),
                'cost_configured' => isset($conf[$id]) ? self::costConfigured($conf[$id]) : null,
            ];
        }
        $byContribution = $out;
        usort($byContribution, static fn(array $a, array $b): int => [$b['contribution'], $b['orders']] <=> [$a['contribution'], $a['orders']]);
        $missing = [];
        foreach ($out as $s) {
            if ($s['incomplete'] > 0 || $s['cost_configured'] === false) {
                $missing[$s['id']] = $s + ['why' => $s['incomplete'] > 0 ? $s['incomplete'] . ' paid order' . ($s['incomplete'] === 1 ? '' : 's') . ' with an unknown cost' : 'the service has no cost set'];
            }
        }
        foreach ($conf as $id => $s) {
            if (!isset($seen[$id]) && $s['status'] === 'active' && !self::costConfigured($s) && ($platformId === null && $serviceId === null && $mode === null)) {
                $missing[$id] = ['id' => $id, 'code' => (string) $s['code'], 'title' => (string) $s['title'], 'mode' => (string) $s['mode'], 'orders' => 0, 'why' => 'the service has no cost set, and has not sold in this period'];
            }
        }
        return [
            'rows' => $out,
            'most_profitable' => array_slice(array_values(array_filter($byContribution, static fn(array $s): bool => $s['contribution'] > 0)), 0, 5),
            'loss_making' => array_values(array_filter(array_reverse($byContribution), static fn(array $s): bool => $s['contribution'] < 0)),
            'missing_cost' => array_values($missing),
        ];
    }

    /** AUTO services need a provider cost, MANUAL services a fulfilment cost (0 is a statement; NULL means "not stated"). */
    private static function costConfigured(array $s): bool
    {
        return $s['mode'] === 'AUTO' ? (int) $s['smm_cost_paise'] > 0 : $s['fulfil_cost_paise'] !== null;
    }

    // ═══════════════════════════ reconciliation ═════════════════════════════

    /**
     * Records whose money is incomplete or inconsistent. Each issue: what, how many, a few examples (so a person can open the order) and the
     * fix that is safe to run, if there is one.
     *
     * @return array{issues:list<array<string,mixed>>,open:int}
     */
    public static function reconciliation(): array
    {
        $s = Finance::settings();
        $issues = [];
        $add = static function (string $key, string $severity, string $title, string $text, int $count, array $sample, ?array $fix = null) use (&$issues): void {
            $issues[] = ['key' => $key, 'severity' => $severity, 'title' => $title, 'text' => $text, 'count' => $count, 'sample' => $sample, 'fix' => $fix];
        };
        $orders = static fn(string $where, array $args = [], string $order = 'o.id DESC'): array => array_map(
            static fn(array $r): array => ['id' => (int) $r['id'], 'public_id' => (string) $r['public_id'], 'service' => (string) $r['service_title'], 'amount_paise' => (int) $r['amount_paise'], 'paid_at' => Clock::iso($r['paid_at'])],
            Db::all("SELECT o.id, o.public_id, o.service_title, o.amount_paise, o.paid_at FROM orders o WHERE o.paid_at IS NOT NULL AND {$where} ORDER BY {$order} LIMIT 8", $args)
        );
        $count = static fn(string $where, array $args = []): int => (int) Db::val("SELECT COUNT(*) FROM orders o WHERE o.paid_at IS NOT NULL AND {$where}", $args);

        // revenue without a verified payment / a verified payment without revenue — these two are money in the wrong place
        $noPay = "NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id AND p.paid_at IS NOT NULL)";
        if (($n = $count($noPay)) > 0) {
            $add('revenue_unverified', 'high', 'Revenue without a verified payment', 'These orders count as paid but no payment of theirs was verified. Check the Payment checks page before trusting the revenue.', $n, $orders($noPay));
        }
        $unpaidOrders = (int) Db::val('SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.paid_at IS NOT NULL AND o.paid_at IS NULL');
        if ($unpaidOrders > 0) {
            $add('payment_without_order_paid', 'high', 'A verified payment whose order is not marked paid', 'The money was verified but the order does not count as revenue. See Payment checks and the order’s timeline.', $unpaidOrders, []);
        }

        // costs that are not known
        if ($s['fee_mode'] !== 'none') {
            $feeSql = "NOT EXISTS (SELECT 1 FROM order_costs pf WHERE pf.order_id = o.id AND pf.cost_type = 'payment_fee' AND pf.superseded_at IS NULL)";
            if (($n = $count($feeSql)) > 0) {
                $fix = $s['fee_mode'] === 'unset' ? null : ['action' => 'backfill_fees', 'label' => 'Add estimated fee lines (' . Finance::feeDescription($s) . ')'];
                $add('fee_missing', 'medium', 'Payment fee not recorded', $s['fee_mode'] === 'unset'
                    ? 'The payment fee model is not set up, so no order has a fee line and profit is incomplete. Set the model under Settings (choose “No fee” if your gateway charges nothing).'
                    : 'These orders were paid before the fee model was set (or without a fee line). The fix adds an estimated line, from the current model, to each; nothing already recorded is changed.', $n, $orders($feeSql), $fix);
            }
        }
        $fulSql = "o.mode = 'MANUAL' AND NOT EXISTS (SELECT 1 FROM order_costs fc WHERE fc.order_id = o.id AND fc.cost_type = 'fulfilment' AND fc.superseded_at IS NULL)";
        if (($n = $count($fulSql)) > 0) {
            $withDefault = $count($fulSql . ' AND EXISTS (SELECT 1 FROM services sv WHERE sv.id = o.service_id AND sv.fulfil_cost_paise IS NOT NULL)');
            $add('fulfilment_missing', 'medium', 'Manual orders without a fulfilment cost', 'Delivering a manual order costs something (staff time, a supplier). Set the service’s standard cost in the service editor, or record the cost on the order. '
                . 'Until then these orders are counted at ₹0 and labelled incomplete.', $n, $orders($fulSql), $withDefault > 0 ? ['action' => 'backfill_fulfilment', 'label' => 'Apply the services’ standard cost to ' . $withDefault . ' order' . ($withDefault === 1 ? '' : 's')] : null);
        }
        $provSql = "o.mode = 'AUTO' AND o.provider_order_id IS NOT NULL AND o.provider_cost_final = 0 AND o.provider_cost_paise = 0";
        if (($n = $count($provSql)) > 0) {
            $add('provider_cost_unknown', 'medium', 'Provider cost unknown', 'The order is at the provider but no cost is known: the service has no cost set and the provider has not reported a charge yet. Sync the order, or set the service’s cost.', $n, $orders($provSql));
        }
        $notFinal = "o.mode = 'AUTO' AND o.provider_cost_final = 0 AND o.provider_cost_paise > 0 AND o.status IN ('COMPLETED', 'PARTIAL', 'CANCELLED') AND o.paid_at < ?";
        $cut = Clock::sql(Clock::now() - 3 * 86400);
        if (($n = $count($notFinal, [$cut])) > 0) {
            $add('provider_cost_not_final', 'low', 'Provider charge never reported', 'These orders finished more than 3 days ago but the provider never reported its final charge, so the estimate from the service is used. Compare with the provider’s invoice.', $n, $orders($notFinal, [$cut]));
        }

        // inconsistent records
        $refSql = "(o.status = 'REFUNDED' AND o.refunded_paise = 0) OR o.refunded_paise > o.amount_paise";
        if (($n = $count($refSql)) > 0) {
            $add('refund_mismatch', 'high', 'Refund status and refunded amount disagree', 'A refunded order has no refunded amount, or the refunded amount is larger than the payment. Record the refund again with the bank reference.', $n, $orders($refSql));
        }
        $lossSql = '(o.amount_paise - o.refunded_paise - CASE WHEN o.mode = \'AUTO\' THEN o.provider_cost_paise ELSE 0 END - ' . Finance::linesSql('o') . ') < 0';
        if (($n = $count($lossSql)) > 0) {
            $add('loss_making_orders', 'info', 'Orders that lost money', 'Refunds and known costs exceed what was collected. Not an error — shown so a price or cost mistake is easy to find.', $n, $orders($lossSql));
        }

        // advertising: estimates stand in for spend nobody entered
        $from = Tz::addDays(Tz::today(), -29);
        $estDays = (int) Db::val(
            'SELECT COUNT(DISTINCT vd.day) FROM visitor_days vd WHERE vd.qualified = 1 AND vd.day >= ? AND NOT EXISTS (SELECT 1 FROM ad_daily_spend a WHERE a.spend_day = vd.day AND a.voided_at IS NULL)',
            [$from]
        );
        if ($estDays > 0) {
            $add('ads_estimated_days', 'info', 'Advertising is estimated for ' . $estDays . ' of the last 30 days', 'Those days use the CPC estimate (qualified visitors × CPC). Enter the actual spend under Ads Intelligence → Spend to replace it.', $estDays, []);
        }
        $open = 0;
        foreach ($issues as $i) {
            if (in_array($i['severity'], ['high', 'medium'], true)) {
                $open += $i['count'] > 0 ? 1 : 0;
            }
        }
        return ['issues' => $issues, 'open' => $open];
    }

    /**
     * The two safe, append-only fixes: add estimated fee lines / apply a service's standard cost to older orders. A dry run only counts.
     *
     * @param array{id:int,username:string,email:string} $admin
     * @return array{action:string,dry_run:bool,orders:int,amount_paise:int,message:string}
     */
    public static function fix(string $action, bool $dry, ?Request $req, array $admin): array
    {
        $s = Finance::settings();
        $touched = [];
        $n = 0;
        $sum = 0;
        $now = Clock::now();
        if ($action === 'backfill_fees') {
            if (in_array($s['fee_mode'], ['unset', 'none'], true)) {
                throw new HttpException(409, 'Set a fee model first (Settings).', 'not_allowed');
            }
            $rows = Db::all(
                'SELECT o.id, o.amount_paise, o.paid_day FROM orders o WHERE o.paid_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM order_costs pf WHERE pf.order_id = o.id AND pf.cost_type = \'payment_fee\' AND pf.superseded_at IS NULL) ORDER BY o.id LIMIT 5000'
            );
            foreach ($rows as $r) {
                $fee = Finance::feeFor((int) $r['amount_paise'], $s) ?? 0;
                $sum += $fee;
                $n++;
                $touched[(string) $r['paid_day']] = true;
                if (!$dry) {
                    Db::exec(
                        'INSERT IGNORE INTO order_costs (order_id, cost_type, amount_paise, source, is_estimate, note, recorded_by, effective_at, created_at, active_key) VALUES (?, \'payment_fee\', ?, \'rate\', 1, ?, ?, ?, ?, ?)',
                        [$r['id'], $fee, 'Added afterwards from the fee model: ' . Finance::feeDescription($s), $admin['id'], Clock::sql($now), Clock::sql($now), $r['id'] . ':payment_fee']
                    );
                }
            }
        } elseif ($action === 'backfill_fulfilment') {
            $rows = Db::all(
                'SELECT o.id, o.paid_day, sv.fulfil_cost_paise AS cost FROM orders o JOIN services sv ON sv.id = o.service_id WHERE o.paid_at IS NOT NULL AND o.mode = \'MANUAL\' AND sv.fulfil_cost_paise IS NOT NULL '
                . 'AND NOT EXISTS (SELECT 1 FROM order_costs fc WHERE fc.order_id = o.id AND fc.cost_type = \'fulfilment\' AND fc.superseded_at IS NULL) ORDER BY o.id LIMIT 5000'
            );
            foreach ($rows as $r) {
                $sum += (int) $r['cost'];
                $n++;
                $touched[(string) $r['paid_day']] = true;
                if (!$dry) {
                    Db::exec(
                        'INSERT IGNORE INTO order_costs (order_id, cost_type, amount_paise, source, is_estimate, note, recorded_by, effective_at, created_at, active_key) VALUES (?, \'fulfilment\', ?, \'service_default\', 1, ?, ?, ?, ?, ?)',
                        [$r['id'], (int) $r['cost'], 'The service’s standard cost, applied afterwards to an order paid before it was set', $admin['id'], Clock::sql($now), Clock::sql($now), $r['id'] . ':fulfilment']
                    );
                }
            }
        } else {
            throw new HttpException(422, 'Unknown fix.', 'invalid');
        }
        if (!$dry && $n > 0) {
            foreach (array_keys($touched) as $day) {
                Analytics::markDirty($day);
            }
            Audit::log('finance.reconcile_fix', 'finance', $action, ['orders' => $n, 'amount' => Money::plain($sum)], $req, $admin);
        }
        $what = $action === 'backfill_fees' ? 'estimated payment-fee line' : 'standard fulfilment-cost line';
        return [
            'action' => $action, 'dry_run' => $dry, 'orders' => $n, 'amount_paise' => $sum,
            'message' => $dry ? ($n === 0 ? 'Nothing to add.' : 'Would add ' . $n . ' ' . $what . ($n === 1 ? '' : 's') . ' totalling ' . Money::inr($sum, true) . '. Nothing was changed.')
                : ($n === 0 ? 'Nothing to add.' : 'Added ' . $n . ' ' . $what . ($n === 1 ? '' : 's') . ' totalling ' . Money::inr($sum, true) . '.'),
        ];
    }
}
