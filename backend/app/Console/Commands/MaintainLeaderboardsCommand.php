<?php

namespace App\Console\Commands;

use App\Domain\Leaderboard\EnsureLeaderboardPeriodsExistAction;
use App\Domain\Leaderboard\FinalizeLeaderboardPeriodAction;
use App\Domain\Leaderboard\RecomputeLeaderboardAction;
use App\Models\LeaderboardPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leaderboards:maintain')]
#[Description('Ensure current leaderboard periods exist, keep provisional ones fresh, and finalize any that have ended')]
class MaintainLeaderboardsCommand extends Command
{
    public function handle(
        EnsureLeaderboardPeriodsExistAction $ensure,
        RecomputeLeaderboardAction $recompute,
        FinalizeLeaderboardPeriodAction $finalize,
    ): int {
        $current = $ensure->execute();

        foreach ($current as $period) {
            $recompute->execute($period);
        }

        $ended = LeaderboardPeriod::where('status', 'provisional')
            ->where('period_end', '<=', now())
            ->get();

        foreach ($ended as $period) {
            $finalize->execute($period);
        }

        $this->line(count($current).' current period(s) refreshed, '.$ended->count().' finalized.');

        return self::SUCCESS;
    }
}
