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
        $nowIst = now()->timezone(self::IST);

        $startIst = match ($type) {
            'daily' => $nowIst->copy()->startOfDay(),
            'weekly' => $nowIst->copy()->startOfWeek(Carbon::MONDAY),
            'monthly' => $nowIst->copy()->startOfMonth(),
            default => throw new \InvalidArgumentException("Unknown leaderboard period type [{$type}]."),
        };

        $endIst = match ($type) {
            'daily' => $startIst->copy()->addDay(),
            'weekly' => $startIst->copy()->addWeek(),
            'monthly' => $startIst->copy()->addMonth(),
        };

        return [
            'start' => $startIst->copy()->timezone('UTC'),
            'end' => $endIst->copy()->timezone('UTC'),
        ];
    }
}
