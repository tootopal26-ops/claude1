<?php

namespace App\Domain\Leaderboard;

use Illuminate\Support\Carbon;

/**
 * Computes the current daily/weekly/monthly period boundaries in IST (the
 * spec's reference timezone for all leaderboard periods — "Daily (IST
 * day)/Weekly (Mon–Sun IST)/Monthly (real calendar month)") and returns
 * them as UTC instants, since every other timestamp in the app is stored
 * and compared in UTC. period_end is always exclusive.
 */
class LeaderboardPeriodCalculator
{
    public const IST = 'Asia/Kolkata';

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public static function currentBounds(string $type): array
    {
        if ($type === 'weekly') {
            return self::weekBoundsFor(now());
        }

        $nowIst = now()->timezone(self::IST);

        $startIst = match ($type) {
            'daily' => $nowIst->copy()->startOfDay(),
            'monthly' => $nowIst->copy()->startOfMonth(),
            default => throw new \InvalidArgumentException("Unknown leaderboard period type [{$type}]."),
        };

        $endIst = match ($type) {
            'daily' => $startIst->copy()->addDay(),
            'monthly' => $startIst->copy()->addMonth(),
        };

        return [
            'start' => $startIst->copy()->timezone('UTC'),
            'end' => $endIst->copy()->timezone('UTC'),
        ];
    }

    /**
     * The Mon–Sun IST week containing an arbitrary instant (not just
     * "now") — shared with Staff Salary's weekly settlement grouping so
     * both domains agree on exactly the same week boundaries.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public static function weekBoundsFor(Carbon $instant): array
    {
        $startIst = $instant->copy()->timezone(self::IST)->startOfWeek(Carbon::MONDAY);
        $endIst = $startIst->copy()->addWeek();

        return [
            'start' => $startIst->copy()->timezone('UTC'),
            'end' => $endIst->copy()->timezone('UTC'),
        ];
    }
}
