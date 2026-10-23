<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DepositOrder;
use App\Models\TournamentResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * The three figures on the profile, each from its source of truth:
     *  - total_earnings: prize coins from the player's current (never superseded) tournament
     *    results — the same definition the leaderboards rank by; deposits and refunds are not earnings.
     *  - matches: tournaments the player has a published result in.
     *  - deposits: coins added through deposit orders the payment provider confirmed (not the
     *    wallet balance, and not pending or failed orders).
     */
    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $results = TournamentResult::query()
            ->whereNull('tournament_results.superseded_by_id')
            ->join('tournament_entries', 'tournament_entries.id', '=', 'tournament_results.tournament_entry_id')
            ->where('tournament_entries.user_id', $userId);

        $deposits = DepositOrder::query()->where('user_id', $userId)->where('status', 'success');

        return response()->json([
            'data' => [
                'total_earnings' => number_format((float) (clone $results)->sum('tournament_results.prize_awarded'), 2, '.', ''),
                'matches' => (int) (clone $results)->distinct()->count('tournament_results.tournament_id'),
                'deposits_total' => number_format((float) $deposits->sum('amount'), 2, '.', ''),
                'deposits_count' => (int) (clone $deposits)->count(),
            ],
        ]);
    }
}
