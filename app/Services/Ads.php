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
 * Advertisement cost model.
 *
 *   ad spend = qualified visitors × CPC
 *
 * A "qualified" visitor stayed on the site at least `qualify_seconds` (default 3).
 * Each qualified visitor is charged ONCE per business day (visitor_days), at the
 * CPC that was in force at that moment — so changing the CPC never rewrites history.
 * CPC is kept as integer paise (₹1.08 = 108): no floating point anywhere.
 */
final class Ads
{
    /** @return array{id:int,cpc_paise:int,qualify_seconds:int,assumed_conversion_bps:int,created_at:string} */
    public static function current(): array
    {
        $r = Db::one('SELECT * FROM advertisement_settings ORDER BY id DESC LIMIT 1');
        if ($r === null) {
            return ['id' => 0, 'cpc_paise' => 108, 'qualify_seconds' => 3, 'assumed_conversion_bps' => 400, 'created_at' => Clock::sql()];
        }
        return [
            'id' => (int) $r['id'], 'cpc_paise' => (int) $r['cpc_paise'], 'qualify_seconds' => (int) $r['qualify_seconds'],
            'assumed_conversion_bps' => (int) $r['assumed_conversion_bps'], 'created_at' => (string) $r['created_at'],
        ];
    }

    public static function cpcPaise(): int
    {
        return self::current()['cpc_paise'];
    }

    /**
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed>
     */
    public static function update(mixed $cpcInput, mixed $qualifySeconds, mixed $assumedPct, Request $req, array $admin): array
    {
        $errors = [];
        $cpc = null;
        try {
            $cpc = Money::parseRupees(is_string($cpcInput) || is_int($cpcInput) || is_float($cpcInput) ? $cpcInput : null);
            if ($cpc > 100000) {
                $errors['cpc'] = 'CPC cannot exceed ₹1,000.';
            }
        } catch (\InvalidArgumentException) {
            $errors['cpc'] = 'Enter the CPC in rupees with at most two decimals (for example 1.08).';
        }
        $q = is_numeric($qualifySeconds) ? (int) $qualifySeconds : 0;
        if ($q < 1 || $q > 60) {
            $errors['qualify_seconds'] = 'Choose between 1 and 60 seconds.';
        }
        $bps = null;
        if (is_numeric($assumedPct) && (float) $assumedPct >= 0.1 && (float) $assumedPct <= 100) {
            $bps = (int) round((float) $assumedPct * 100);
        } else {
            $errors['assumed_conversion'] = 'Enter a conversion rate between 0.1 and 100 (%).';
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        $old = self::current();
        Db::insert('advertisement_settings', [
            'cpc_paise' => $cpc, 'qualify_seconds' => $q, 'assumed_conversion_bps' => $bps, 'set_by' => $admin['id'], 'created_at' => Clock::sql(),
        ]);
        Audit::log('settings.advertisement', 'advertisement', null, [
            'cpc_from' => Money::plain($old['cpc_paise']), 'cpc_to' => Money::plain((int) $cpc), 'qualify_seconds' => $q,
        ], $req, $admin);
        return self::current();
    }

    /** @return list<array<string,mixed>> */
    public static function history(int $limit = 15): array
    {
        $rows = Db::all(
            'SELECT a.cpc_paise, a.qualify_seconds, a.created_at, ad.username FROM advertisement_settings a LEFT JOIN admins ad ON ad.id = a.set_by ORDER BY a.id DESC LIMIT ?',
            [$limit]
        );
        return array_map(static fn(array $r): array => [
            'cpc_paise' => (int) $r['cpc_paise'], 'cpc' => Money::inr((int) $r['cpc_paise'], true),
            'qualify_seconds' => (int) $r['qualify_seconds'], 'at' => Clock::iso($r['created_at']), 'by' => $r['username'] ?: 'system',
        ], $rows);
    }

    /**
     * Expected advertising cost carried by ONE paid order — used by the service
     * profit preview. Real data wins (last 30 days of ad spend ÷ paid orders);
     * with too little history it falls back to CPC ÷ assumed conversion rate.
     *
     * @return array{paise:int,basis:string}
     */
    public static function estimatedPerOrder(): array
    {
        $cur = self::current();
        $from = Tz::addDays(Tz::today(), -29);
        $spend = (int) Db::val('SELECT COALESCE(SUM(cpc_paise), 0) FROM visitor_days WHERE qualified = 1 AND day >= ?', [$from]);
        $orders = (int) Db::val('SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL AND paid_day >= ?', [$from]);
        if ($orders >= 10 && $spend > 0) {
            return [
                'paise' => Money::mulDiv($spend, 1, $orders),
                'basis' => 'last 30 days: ' . Money::inr($spend, true) . ' ads ÷ ' . $orders . ' paid orders',
            ];
        }
        return [
            'paise' => Money::mulDiv($cur['cpc_paise'], 10000, max(1, $cur['assumed_conversion_bps'])),
            'basis' => 'CPC ' . Money::inr($cur['cpc_paise'], true) . ' ÷ assumed ' . Money::tenthsToString((int) round($cur['assumed_conversion_bps'] / 10)) . '% conversion',
        ];
    }
}
