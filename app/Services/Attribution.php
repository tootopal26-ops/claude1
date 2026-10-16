<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;

/**
 * Which campaign gets the credit for a verified order.
 *
 * MODEL `last_non_direct`: of the visitor's sessions that started within the attribution window BEFORE the payment was verified, the most recent
 * session that arrived through something other than "direct" (a campaign tag, a source tag, or a referring site) gets the credit. When there is
 * none the order is `direct`. The window is a setting (1–90 days, default 7).
 *
 * It is decided ONCE, on the server, from the session rows the server wrote earlier, when the payment is verified (Settlement), and stored with the
 * model and window it used (campaign_attribution, one row per order, never updated). Nothing the browser sends after the payment can change it.
 * A row missed because the process died after the commit is filled in later by backfill(), from the same server-side data.
 *
 * Attribution is a reporting convention, not proof of cause: "attributed revenue" is revenue from visitors whose last non-direct touch was the
 * campaign — it is NOT incremental revenue (what would not have happened without the ad), and the screens never call it that.
 */
final class Attribution
{
    public const MODEL = 'last_non_direct';
    public const MODEL_LABEL = 'last non-direct touch';
    public const DEFAULT_WINDOW = 7;

    public static function windowDays(): int
    {
        return max(1, min(90, (int) Settings::get('ads.attribution_window_days', self::DEFAULT_WINDOW)));
    }

    /**
     * @param array<string,mixed> $in {window_days}
     * @param array{id:int,username:string,email:string} $admin
     * @return array{window_days:int,model:string}
     */
    public static function saveSettings(array $in, ?Request $req, array $admin): array
    {
        $old = self::windowDays();
        $raw = $in['window_days'] ?? $old;
        if (!is_numeric($raw) || (string) (int) $raw !== trim((string) $raw) || (int) $raw < 1 || (int) $raw > 90) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => ['window_days' => 'Enter a whole number of days from 1 to 90.']]);
        }
        $days = (int) $raw;
        if ($days !== $old) {
            Settings::set('ads.attribution_window_days', $days, $admin['id']);
            Audit::log('ads.attribution_changed', 'settings', 'ads', ['window_days_from' => $old, 'window_days_to' => $days, 'model' => self::MODEL], $req, $admin);
        }
        return ['window_days' => self::windowDays(), 'model' => self::MODEL];
    }

    /**
     * Attribute one verified order. Idempotent: an order is attributed at most once and never re-attributed.
     *
     * @return bool true when a row was written by this call
     */
    public static function record(int $orderId): bool
    {
        $o = Db::one('SELECT id, visitor_id, visitor_session_id, paid_at FROM orders WHERE id = ? AND paid_at IS NOT NULL', [$orderId]);
        if ($o === null || Db::val('SELECT 1 FROM campaign_attribution WHERE order_id = ?', [$orderId]) !== null) {
            return false;
        }
        $window = self::windowDays();
        $paidTs = (int) Clock::fromSql((string) $o['paid_at']);
        $touch = null;
        $fallback = null;
        if ($o['visitor_id'] !== null) {
            $sessions = Db::all(
                'SELECT id, started_at, source, referrer_host, utm_source, utm_medium, utm_campaign, utm_content, utm_term FROM visitor_sessions '
                . 'WHERE visitor_id = ? AND started_at >= ? AND started_at <= ? ORDER BY started_at DESC, id DESC LIMIT 60',
                [$o['visitor_id'], Clock::sql($paidTs - $window * 86400), Clock::sql($paidTs)]
            );
            foreach ($sessions as $s) {
                $kind = self::kind($s);
                if ($fallback === null && (int) $s['id'] === (int) $o['visitor_session_id']) {
                    $fallback = $s;
                }
                if ($kind !== 'direct') {
                    $touch = $s + ['kind' => $kind];
                    break;
                }
            }
        }
        $row = $touch ?? ($fallback !== null ? $fallback + ['kind' => 'direct'] : null);
        $n = Db::exec(
            'INSERT IGNORE INTO campaign_attribution (order_id, visitor_session_id, model, window_days, touch_kind, utm_source, utm_medium, utm_campaign, utm_content, utm_term, source, touch_at, attributed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $orderId, $row['id'] ?? null, self::MODEL, $window, $touch['kind'] ?? 'direct',
                $touch['utm_source'] ?? null, $touch['utm_medium'] ?? null, $touch['utm_campaign'] ?? null, $touch['utm_content'] ?? null, $touch['utm_term'] ?? null,
                $touch !== null ? (string) $touch['source'] : 'direct', $touch['started_at'] ?? null, Clock::sql(),
            ]
        );
        return $n === 1;
    }

    /** campaign (a campaign tag), source (a source tag or a referring site), or direct. */
    public static function kind(array $session): string
    {
        if (($session['utm_campaign'] ?? null) !== null && $session['utm_campaign'] !== '') {
            return 'campaign';
        }
        if ((($session['utm_source'] ?? null) !== null && $session['utm_source'] !== '') || (string) ($session['source'] ?? 'direct') !== 'direct') {
            return 'source';
        }
        return 'direct';
    }

    /**
     * Verified orders that have no attribution row — a crash between the commit and the attribution, or an order paid before attribution was recorded.
     * The same server-side session rows decide it later as would have decided it then; the window used is stored with the row, so a later reader can tell.
     * Idempotent: an order that has a row is never touched.
     *
     * @return int how many orders were attributed (or, for a dry run, would be)
     */
    public static function backfill(int $limit = 200, int $sinceDays = 14, bool $dry = false): int
    {
        $ids = self::missingIds($limit, $sinceDays);
        if ($dry) {
            return count($ids);
        }
        $n = 0;
        foreach ($ids as $id) {
            $n += self::record($id) ? 1 : 0;
        }
        return $n;
    }

    /** How many verified orders in the last $sinceDays have no attribution row. */
    public static function missing(int $sinceDays = 3650): int
    {
        return (int) Db::val(
            'SELECT COUNT(*) FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_at >= ? AND NOT EXISTS (SELECT 1 FROM campaign_attribution ca WHERE ca.order_id = o.id)',
            [Clock::sql(Clock::now() - max(1, $sinceDays) * 86400)]
        );
    }

    /** @return list<int> */
    private static function missingIds(int $limit, int $sinceDays): array
    {
        return array_map('intval', Db::col(
            'SELECT o.id FROM orders o WHERE o.paid_at IS NOT NULL AND o.paid_at >= ? AND NOT EXISTS (SELECT 1 FROM campaign_attribution ca WHERE ca.order_id = o.id) ORDER BY o.id LIMIT ' . max(1, min(5000, $limit)),
            [Clock::sql(Clock::now() - max(1, $sinceDays) * 86400)]
        ));
    }
}
