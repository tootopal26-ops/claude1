<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Support\Clock;
use App\Support\Profit;
use App\Support\Tz;

/**
 * Daily metrics. One SQL implementation (computeDays) feeds both the live
 * dashboard queries and the persisted analytics_daily rollup, so the two can
 * never disagree.
 *
 * Attribution rules (all days are BUSINESS-timezone calendar days):
 *   revenue, profit, SMM spend, orders_paid ... → the day the order was PAID
 *   orders_created                             → the day the order was created
 *   payments_failed / payments_expired         → the day the payment was created
 *   visitors, qualified, ads spend             → visitor_days (one row per visitor per day)
 *
 * Rollup freshness: a stored day is trusted only if it is not dirty AND was
 * computed AFTER that day ended (so a half-finished day is recomputed once).
 * Anything that changes an order's money marks its day dirty.
 */
final class Analytics
{
    /** Metric keys == analytics_daily columns (minus day/dirty/computed_at). */
    public const KEYS = [
        'orders_created', 'orders_paid', 'orders_completed', 'revenue_paise', 'refunds_paise', 'smm_spend_paise', 'gross_profit_paise',
        'ads_spend_paise', 'net_profit_paise', 'auto_orders', 'manual_orders', 'auto_revenue_paise', 'manual_revenue_paise',
        'auto_profit_paise', 'manual_profit_paise', 'payments_success', 'payments_failed', 'payments_expired', 'visitors',
        'qualified_visitors', 'order_visitors', 'paid_visitors', 'desktop_visitors', 'mobile_visitors', 'tablet_visitors',
        'src_instagram', 'src_youtube', 'src_direct', 'src_other',
    ];

    /** @var array<string,true> */
    private static array $dirtyMemo = [];

    /** @return array<string,int> */
    public static function zero(): array
    {
        return array_fill_keys(self::KEYS, 0);
    }

    public static function markDirty(string $day): void
    {
        if (isset(self::$dirtyMemo[$day]) || !Tz::isDay($day)) {
            return;
        }
        self::$dirtyMemo[$day] = true;
        Db::exec(
            'INSERT INTO analytics_daily (day, dirty, computed_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE dirty = 1',
            [$day, Clock::sql()]
        );
    }

    /** First business day with any data (orders or visitors); today if none. */
    public static function firstDay(): string
    {
        $o = Db::val('SELECT MIN(created_day) FROM orders');
        $v = Db::val('SELECT MIN(day) FROM visitor_days');
        $c = array_filter([$o, $v]);
        return $c ? (string) min($c) : Tz::today();
    }

    /**
     * Compute metrics per day for [$from, $to] (inclusive) straight from the
     * source tables. Order-based metrics honour the optional filters; visitor
     * and advertising metrics are always site-wide.
     *
     * @return array<string,array<string,int>> keyed by 'Y-m-d', every day present
     */
    public static function computeDays(string $from, string $to, ?int $platformId = null, ?string $mode = null): array
    {
        $days = [];
        for ($d = $from; $d <= $to; $d = Tz::addDays($d, 1)) {
            $days[$d] = self::zero();
        }
        if (!$days) {
            return [];
        }

        [$of, $op] = self::orderFilter($platformId, $mode);

        // ── paid orders → revenue / profit / SMM spend (by paid_day)
        $rows = Db::all(
            'SELECT o.paid_day AS d, COUNT(*) AS n, SUM(o.amount_paise - o.refunded_paise) AS revenue, SUM(o.refunded_paise) AS refunds, '
            . "SUM(CASE WHEN o.mode = 'AUTO' THEN o.provider_cost_paise ELSE 0 END) AS smm, "
            . "SUM(CASE WHEN o.status IN ('COMPLETED','MANUAL_APPROVED') THEN 1 ELSE 0 END) AS done, "
            . "SUM(CASE WHEN o.mode = 'AUTO' THEN 1 ELSE 0 END) AS auto_n, SUM(CASE WHEN o.mode = 'MANUAL' THEN 1 ELSE 0 END) AS manual_n, "
            . "SUM(CASE WHEN o.mode = 'AUTO' THEN o.amount_paise - o.refunded_paise ELSE 0 END) AS auto_rev, "
            . "SUM(CASE WHEN o.mode = 'MANUAL' THEN o.amount_paise - o.refunded_paise ELSE 0 END) AS manual_rev "
            . 'FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ?' . $of . ' GROUP BY o.paid_day',
            array_merge([$from, $to], $op)
        );
        foreach ($rows as $r) {
            $m = &$days[(string) $r['d']];
            $m['orders_paid'] = (int) $r['n'];
            $m['orders_completed'] = (int) $r['done'];
            $m['revenue_paise'] = (int) $r['revenue'];
            $m['refunds_paise'] = (int) $r['refunds'];
            $m['smm_spend_paise'] = (int) $r['smm'];
            $m['gross_profit_paise'] = (int) $r['revenue'] - (int) $r['smm'];
            $m['auto_orders'] = (int) $r['auto_n'];
            $m['manual_orders'] = (int) $r['manual_n'];
            $m['auto_revenue_paise'] = (int) $r['auto_rev'];
            $m['manual_revenue_paise'] = (int) $r['manual_rev'];
            $m['manual_profit_paise'] = (int) $r['manual_rev'];                       // MANUAL: 100 % is profit
            $m['auto_profit_paise'] = (int) $r['auto_rev'] - (int) $r['smm'];          // AUTO: revenue − provider cost
            unset($m);
        }

        // ── orders created (by created_day)
        foreach (Db::all('SELECT o.created_day AS d, COUNT(*) AS n FROM orders o WHERE o.created_day BETWEEN ? AND ?' . $of . ' GROUP BY o.created_day', array_merge([$from, $to], $op)) as $r) {
            $days[(string) $r['d']]['orders_created'] = (int) $r['n'];
        }

        // ── payments
        $join = $of !== '' ? ' JOIN orders o ON o.id = p.order_id' : '';
        foreach (Db::all('SELECT p.paid_day AS d, COUNT(*) AS n FROM payments p' . $join . ' WHERE p.paid_at IS NOT NULL AND p.paid_day BETWEEN ? AND ?' . $of . ' GROUP BY p.paid_day', array_merge([$from, $to], $op)) as $r) {
            $days[(string) $r['d']]['payments_success'] = (int) $r['n'];
        }
        foreach (Db::all(
            "SELECT p.created_day AS d, SUM(CASE WHEN p.status IN ('failed','mismatch') AND p.paid_at IS NULL THEN 1 ELSE 0 END) AS f, "
            . "SUM(CASE WHEN p.status = 'expired' AND p.paid_at IS NULL THEN 1 ELSE 0 END) AS x FROM payments p" . $join
            . ' WHERE p.created_day BETWEEN ? AND ?' . $of . ' GROUP BY p.created_day',
            array_merge([$from, $to], $op)
        ) as $r) {
            $days[(string) $r['d']]['payments_failed'] = (int) $r['f'];
            $days[(string) $r['d']]['payments_expired'] = (int) $r['x'];
        }

        // ── visitors & advertising (site-wide)
        foreach (Db::all(
            'SELECT day AS d, COUNT(*) AS v, SUM(qualified) AS q, SUM(order_created) AS oc, SUM(payment_succeeded) AS ps, '
            . "SUM(CASE WHEN device_type = 'desktop' THEN 1 ELSE 0 END) AS dd, SUM(CASE WHEN device_type = 'mobile' THEN 1 ELSE 0 END) AS dm, "
            . "SUM(CASE WHEN device_type = 'tablet' THEN 1 ELSE 0 END) AS dt, "
            . "SUM(CASE WHEN source = 'instagram' THEN 1 ELSE 0 END) AS si, SUM(CASE WHEN source = 'youtube' THEN 1 ELSE 0 END) AS sy, "
            . "SUM(CASE WHEN source = 'direct' THEN 1 ELSE 0 END) AS sd, SUM(CASE WHEN source = 'other' THEN 1 ELSE 0 END) AS so, "
            . 'SUM(CASE WHEN qualified = 1 THEN cpc_paise ELSE 0 END) AS ads FROM visitor_days WHERE day BETWEEN ? AND ? GROUP BY day',
            [$from, $to]
        ) as $r) {
            $m = &$days[(string) $r['d']];
            $m['visitors'] = (int) $r['v'];
            $m['qualified_visitors'] = (int) $r['q'];
            $m['order_visitors'] = (int) $r['oc'];
            $m['paid_visitors'] = (int) $r['ps'];
            $m['desktop_visitors'] = (int) $r['dd'];
            $m['mobile_visitors'] = (int) $r['dm'];
            $m['tablet_visitors'] = (int) $r['dt'];
            $m['src_instagram'] = (int) $r['si'];
            $m['src_youtube'] = (int) $r['sy'];
            $m['src_direct'] = (int) $r['sd'];
            $m['src_other'] = (int) $r['so'];
            $m['ads_spend_paise'] = (int) $r['ads'];
            unset($m);
        }

        foreach ($days as &$m) {
            $m['net_profit_paise'] = $m['gross_profit_paise'] - $m['ads_spend_paise'];
        }
        unset($m);
        return $days;
    }

    /**
     * Unfiltered daily metrics for [$from, $to], served from analytics_daily
     * (refreshing stale rows first). Today is always computed live.
     *
     * @return array<string,array<string,int>>
     */
    public static function rollup(string $from, string $to): array
    {
        $today = Tz::today();
        $past = min($to, Tz::addDays($today, -1));
        if ($from <= $past) {
            self::ensureFresh($from, $past);
        }
        $out = [];
        if ($from <= $past) {
            $cols = implode(', ', array_map(static fn(string $k): string => "`{$k}`", self::KEYS));
            foreach (Db::all("SELECT day, {$cols} FROM analytics_daily WHERE day BETWEEN ? AND ?", [$from, $past]) as $r) {
                $d = (string) $r['day'];
                unset($r['day']);
                $out[$d] = array_map('intval', $r);
            }
        }
        if ($to >= $today && $from <= $today) {
            $out += self::computeDays(max($from, $today), $today);
        }
        $days = [];
        for ($d = $from; $d <= $to; $d = Tz::addDays($d, 1)) {
            $days[$d] = $out[$d] ?? self::zero();
        }
        return $days;
    }

    /** Recompute any stored day in [$from, $to] that is missing, dirty or was computed before its day ended. */
    public static function ensureFresh(string $from, string $to): int
    {
        $stored = [];
        foreach (Db::all('SELECT day, dirty, computed_at FROM analytics_daily WHERE day BETWEEN ? AND ?', [$from, $to]) as $r) {
            $stored[(string) $r['day']] = $r;
        }
        $stale = [];
        for ($d = $from; $d <= $to; $d = Tz::addDays($d, 1)) {
            $r = $stored[$d] ?? null;
            if ($r === null || (int) $r['dirty'] === 1 || Tz::dayOf((int) Clock::fromSql((string) $r['computed_at'])) <= $d) {
                $stale[] = $d;
            }
        }
        if (!$stale) {
            return 0;
        }
        $computed = self::computeDays($stale[0], end($stale));
        $now = Clock::sql();
        $cols = self::KEYS;
        $sql = 'INSERT INTO analytics_daily (day, ' . implode(', ', array_map(static fn($k) => "`{$k}`", $cols)) . ', dirty, computed_at) VALUES (?, '
            . implode(', ', array_fill(0, count($cols), '?')) . ', 0, ?) ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(static fn($k) => "`{$k}` = VALUES(`{$k}`)", $cols)) . ', dirty = 0, computed_at = VALUES(computed_at)';
        foreach ($stale as $d) {
            Db::exec($sql, array_merge([$d], array_values($computed[$d]), [$now]));
        }
        return count($stale);
    }

    /** @return array{0:string,1:list<mixed>} extra WHERE fragment (alias o) + params */
    public static function orderFilter(?int $platformId, ?string $mode): array
    {
        $sql = '';
        $params = [];
        if ($platformId !== null) {
            $sql .= ' AND o.platform_id = ?';
            $params[] = $platformId;
        }
        if ($mode === 'AUTO' || $mode === 'MANUAL') {
            $sql .= ' AND o.mode = ?';
            $params[] = $mode;
        }
        return [$sql, $params];
    }

    /** Sum several day-metric arrays. @param iterable<array<string,int>> $days @return array<string,int> */
    public static function sum(iterable $days): array
    {
        $t = self::zero();
        foreach ($days as $m) {
            foreach (self::KEYS as $k) {
                $t[$k] += $m[$k] ?? 0;
            }
        }
        return $t;
    }

    /** Drop rows older than needed? (kept forever — they are tiny.) Rebuild everything (admin tool). */
    public static function rebuildAll(): int
    {
        Db::exec('UPDATE analytics_daily SET dirty = 1');
        $first = self::firstDay();
        $yesterday = Tz::addDays(Tz::today(), -1);
        return $first <= $yesterday ? self::ensureFresh($first, $yesterday) : 0;
    }

    /** Verification helper used by tests: the SQL profit rule applied to ALL paid orders in a range. */
    public static function grossProfitDirect(string $from, string $to): int
    {
        return (int) Db::val('SELECT COALESCE(SUM(' . Profit::SQL_GROSS . '), 0) FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN ? AND ?', [$from, $to]);
    }
}
