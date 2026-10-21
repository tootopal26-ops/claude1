<?php

namespace App\Domain\Leaderboard;

use App\Models\LeaderboardEntry;
use App\Models\LeaderboardPeriod;
use App\Models\TournamentResult;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds a period's leaderboard_entries from scratch by aggregating the
 * CURRENT (non-superseded) tournament_results whose tournament started
 * within the period's bounds — this is a derived cache, never itself the
 * source of truth, so a later result correction is simply picked up the
 * next time this runs rather than needing its own correction logic.
 *
 * Ranking is score (total prize awarded) descending, then total kills
 * descending, then user_id ascending — a deterministic order with no
 * possible tie, since user_id is unique.
 *
 * A finalized period's entries are locked: recomputing it is a no-op.
 */
class RecomputeLeaderboardAction
{
    public function execute(LeaderboardPeriod $period): void
    {
        if ($period->isFinalized()) {
            return;
        }

        $rows = TournamentResult::query()
            ->whereNull('tournament_results.superseded_by_id')
            ->join('tournaments', 'tournaments.id', '=', 'tournament_results.tournament_id')
            ->join('tournament_entries', 'tournament_entries.id', '=', 'tournament_results.tournament_entry_id')
            ->where('tournaments.scheduled_start_at', '>=', $period->period_start)
            ->where('tournaments.scheduled_start_at', '<', $period->period_end)
            ->selectRaw('tournament_entries.user_id as user_id')
            ->selectRaw('SUM(tournament_results.prize_awarded) as score')
            ->selectRaw('SUM(tournament_results.kills) as total_kills')
            ->selectRaw('COUNT(DISTINCT tournament_results.tournament_id) as tournaments_played')
            ->groupBy('tournament_entries.user_id')
            ->orderByDesc('score')
            ->orderByDesc('total_kills')
            ->orderBy('tournament_entries.user_id')
            ->get();

        DB::transaction(function () use ($period, $rows) {
            LeaderboardEntry::where('leaderboard_period_id', $period->id)->delete();

            $rank = 1;

            foreach ($rows as $row) {
                LeaderboardEntry::create([
                    'leaderboard_period_id' => $period->id,
                    'user_id' => $row->user_id,
                    'rank' => $rank++,
                    'score' => (string) $row->score,
                    'total_kills' => (int) $row->total_kills,
                    'tournaments_played' => (int) $row->tournaments_played,
                ]);
            }
        });
    }
}
