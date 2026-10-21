<?php

namespace App\Domain\Leaderboard;

use App\Models\LeaderboardPeriod;

/**
 * Lazily creates the current daily/weekly/monthly period row the first
 * time anything asks for it — there is no need to pre-generate future
 * periods ahead of time, only to guarantee "the current one" always
 * exists when a player, staff member, or the finalize command looks for it.
 */
class EnsureLeaderboardPeriodsExistAction
{
    /**
     * @return array<string, LeaderboardPeriod>
     */
    public function execute(): array
    {
        $periods = [];

        foreach (['daily', 'weekly', 'monthly'] as $type) {
            $bounds = LeaderboardPeriodCalculator::currentBounds($type);

            $periods[$type] = LeaderboardPeriod::firstOrCreate(
                ['type' => $type, 'period_start' => $bounds['start']],
                ['period_end' => $bounds['end'], 'status' => 'provisional'],
            );
        }

        return $periods;
    }
}
