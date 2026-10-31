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
 * A periodic revenue goal the admin sets (e.g. "₹50,000 this month"), and real pacing against it —
 * never a separate revenue computation: `progress()` reads the exact same `analytics_daily` rollup
 * (App\Services\Analytics) the dashboard itself uses, so a figure here never disagrees with it.
 *
 * Append-only / audited, like every other settings history table in this application: setting a new
 * target revokes (never deletes or overwrites) any existing, not-yet-revoked target whose period
 * overlaps the new one, so `current()` always has at most one unambiguous answer for "today".
 */
final class SalesTarget
{
    public const MAX_TARGET_PAISE = 1_000_000_000; // ₹1,00,00,000 (1 crore) — generous, guards against a typo, not a real business cap
    public const MAX_PERIOD_DAYS = 366;

    /** @return array<string,mixed>|null the target whose period covers today, or null if none is set */
    public static function current(): ?array
    {
        $today = Tz::today();
        $r = Db::one(
            'SELECT t.*, a.username FROM sales_targets t LEFT JOIN admins a ON a.id = t.set_by '
            . 'WHERE t.revoked_at IS NULL AND t.period_start <= ? AND t.period_end >= ? ORDER BY t.created_at DESC, t.id DESC LIMIT 1',
            [$today, $today]
        );
        return $r === null ? null : self::row($r);
    }

    /**
     * @param array{period_start?:mixed,period_end?:mixed,target?:mixed,note?:mixed} $in
     * @param array{id:int,username:string} $admin
     * @return array<string,mixed> progress() of the newly set target
     */
    public static function set(array $in, Request $req, array $admin): array
    {
        $errors = [];
        $start = is_string($in['period_start'] ?? null) ? trim($in['period_start']) : '';
        $end = is_string($in['period_end'] ?? null) ? trim($in['period_end']) : '';
        if (!Tz::isDay($start)) {
            $errors['period_start'] = 'Choose a start date.';
        }
        if (!Tz::isDay($end)) {
            $errors['period_end'] = 'Choose an end date.';
        }
        if (!$errors && $end < $start) {
            $errors['period_end'] = 'The end date must be on or after the start date.';
        } elseif (!$errors && Tz::daysBetween($start, $end) + 1 > self::MAX_PERIOD_DAYS) {
            $errors['period_end'] = 'A target can cover at most ' . self::MAX_PERIOD_DAYS . ' days.';
        }
        $target = null;
        try {
            $target = Money::parseRupees(is_string($in['target'] ?? null) || is_int($in['target'] ?? null) || is_float($in['target'] ?? null) ? $in['target'] : null);
            if ($target <= 0) {
                $errors['target'] = 'Enter a target greater than ₹0.';
            } elseif ($target > self::MAX_TARGET_PAISE) {
                $errors['target'] = 'That target is larger than ' . Money::inr(self::MAX_TARGET_PAISE, true) . '.';
            }
        } catch (\InvalidArgumentException) {
            $errors['target'] = 'Enter the target in rupees with at most two decimals.';
        }
        $note = is_string($in['note'] ?? null) ? trim($in['note']) : '';
        if (mb_strlen($note) > 255) {
            $errors['note'] = 'Keep the note under 255 characters.';
        }
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }

        $superseded = Db::col('SELECT id FROM sales_targets WHERE revoked_at IS NULL AND period_start <= ? AND period_end >= ?', [$end, $start]);
        $now = Clock::sql();
        $id = Db::tx(function () use ($start, $end, $target, $note, $admin, $superseded, $now): int {
            if ($superseded) {
                Db::exec('UPDATE sales_targets SET revoked_at = ? WHERE id IN (' . Db::in($superseded) . ')', array_merge([$now], $superseded));
            }
            return Db::insert('sales_targets', [
                'period_start' => $start, 'period_end' => $end, 'target_paise' => $target,
                'note' => $note !== '' ? $note : null, 'set_by' => $admin['id'], 'created_at' => $now,
            ]);
        });
        Audit::log('settings.sales_target', 'sales_target', $id, [
            'period_start' => $start, 'period_end' => $end, 'target' => Money::plain($target), 'superseded' => $superseded,
        ], $req, $admin);
        return self::progress(self::byId($id));
    }

    /** Ends the current target's period early (e.g. a goal no longer applies) — an explicit, audited admin decision, not a delete. */
    public static function revoke(Request $req, array $admin): array
    {
        $cur = self::current();
        if ($cur === null) {
            throw new HttpException(409, 'There is no current target to end.', 'no_current');
        }
        Db::exec('UPDATE sales_targets SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [Clock::sql(), $cur['id']]);
        Audit::log('settings.sales_target.revoke', 'sales_target', $cur['id'], ['period_start' => $cur['period_start'], 'period_end' => $cur['period_end']], $req, $admin);
        return self::progress(null);
    }

    /** @return list<array<string,mixed>> */
    public static function history(int $limit = 15): array
    {
        $rows = Db::all(
            'SELECT t.*, a.username FROM sales_targets t LEFT JOIN admins a ON a.id = t.set_by ORDER BY t.created_at DESC, t.id DESC LIMIT ?',
            [$limit]
        );
        return array_map(self::row(...), $rows);
    }

    /**
     * Real pacing against a target: actual revenue so far (from the same rollup the dashboard uses),
     * days elapsed vs. the whole period, a simple linear projection to the period's end, and the daily
     * pace still needed to reach the target. A period that has not started yet, or has already ended,
     * is named as such rather than projected.
     *
     * @param array<string,mixed>|null $target defaults to current()
     * @return array<string,mixed>
     */
    public static function progress(?array $target = null): array
    {
        $target ??= self::current();
        $today = Tz::today();
        if ($target === null) {
            return ['has_target' => false, 'target' => null, 'today' => $today];
        }

        $totalDays = Tz::daysBetween($target['period_start'], $target['period_end']) + 1;
        $base = ['has_target' => true, 'target' => $target, 'today' => $today, 'total_days' => $totalDays];

        if ($today < $target['period_start']) {
            return $base + [
                'status' => 'not_started', 'actual_paise' => 0, 'elapsed_days' => 0, 'remaining_days' => $totalDays,
                'pct_of_target_tenths' => 0, 'daily_pace_paise' => null, 'projected_paise' => null, 'projected_pct_tenths' => null,
                'pace_label' => null, 'remaining_to_target_paise' => $target['target_paise'],
                'required_daily_pace_paise' => Money::mulDiv($target['target_paise'], 1, $totalDays),
            ];
        }

        $ended = $today > $target['period_end'];
        $upTo = $ended ? $target['period_end'] : $today;
        $elapsedDays = Tz::daysBetween($target['period_start'], $upTo) + 1;
        $days = Analytics::rollup($target['period_start'], $upTo);
        $actual = Analytics::sum($days)['revenue_paise'];
        $pctTenths = Money::pctTenths($actual, $target['target_paise']);
        $remainingDays = max(0, $totalDays - $elapsedDays);
        $remainingToTarget = max(0, $target['target_paise'] - $actual);

        if ($ended) {
            return $base + [
                'status' => 'ended', 'actual_paise' => $actual, 'elapsed_days' => $elapsedDays, 'remaining_days' => 0,
                'pct_of_target_tenths' => $pctTenths, 'daily_pace_paise' => Money::mulDiv($actual, 1, $elapsedDays),
                'projected_paise' => $actual, 'projected_pct_tenths' => $pctTenths, 'pace_label' => self::label($pctTenths),
                'remaining_to_target_paise' => $remainingToTarget, 'required_daily_pace_paise' => null,
            ];
        }

        $dailyPace = Money::mulDiv($actual, 1, $elapsedDays);
        $projected = Money::mulDiv($dailyPace, $totalDays, 1);
        $projectedPct = Money::pctTenths($projected, $target['target_paise']);
        $requiredDaily = $remainingToTarget <= 0 ? 0 : ($remainingDays > 0 ? Money::mulDiv($remainingToTarget, 1, $remainingDays) : $remainingToTarget);
        return $base + [
            'status' => 'in_progress', 'actual_paise' => $actual, 'elapsed_days' => $elapsedDays, 'remaining_days' => $remainingDays,
            'pct_of_target_tenths' => $pctTenths, 'daily_pace_paise' => $dailyPace, 'projected_paise' => $projected,
            'projected_pct_tenths' => $projectedPct, 'pace_label' => self::label($projectedPct),
            'remaining_to_target_paise' => $remainingToTarget, 'required_daily_pace_paise' => $requiredDaily,
        ];
    }

    private static function label(int $pctTenths): string
    {
        if ($pctTenths >= 1050) {
            return 'ahead';
        }
        return $pctTenths >= 950 ? 'on_track' : 'behind';
    }

    private static function byId(int $id): ?array
    {
        $r = Db::one('SELECT t.*, a.username FROM sales_targets t LEFT JOIN admins a ON a.id = t.set_by WHERE t.id = ?', [$id]);
        return $r === null ? null : self::row($r);
    }

    /** @return array<string,mixed> */
    private static function row(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'period_start' => (string) $r['period_start'], 'period_end' => (string) $r['period_end'],
            'target_paise' => (int) $r['target_paise'], 'target' => Money::plain((int) $r['target_paise']),
            'note' => $r['note'], 'set_by' => $r['username'] ?: 'system', 'created_at' => Clock::iso((string) $r['created_at']),
            'revoked_at' => $r['revoked_at'] !== null ? Clock::iso((string) $r['revoked_at']) : null,
        ];
    }
}
