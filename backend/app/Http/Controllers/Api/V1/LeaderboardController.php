<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Leaderboard\EnsureLeaderboardPeriodsExistAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaderboardBannerResource;
use App\Http\Resources\LeaderboardResource;
use App\Models\LeaderboardBanner;
use App\Models\LeaderboardPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    private const VALID_TYPES = ['daily', 'weekly', 'monthly'];

    private const TOP_N = 50;

    /**
     * The carousel at the top of the leaderboard screen: enabled banners that
     * have a picture, in the admin's order.
     */
    public function banners(): JsonResponse
    {
        $banners = LeaderboardBanner::query()->visibleInApp()->with(['imageAsset', 'thumbnailAsset'])->get();

        return response()->json([
            'data' => LeaderboardBannerResource::collection($banners),
        ]);
    }

    /**
     * Reads the cached rollup as of the last scheduled maintenance run
     * (every 10 minutes) — it does not recompute on every request, which
     * would be wasteful for a read this frequent.
     */
    public function show(Request $request, string $type, EnsureLeaderboardPeriodsExistAction $ensure): JsonResponse
    {
        abort_unless(in_array($type, self::VALID_TYPES, true), 404);

        $period = LeaderboardPeriod::where('type', $type)->orderByDesc('period_start')->first()
            ?? $ensure->execute()[$type];

        $topEntries = $period->entries()->with('user')->orderBy('rank')->limit(self::TOP_N)->get();

        $myEntry = $period->entries()->with('user')->where('user_id', $request->user()->id)->first();

        return response()->json([
            'data' => new LeaderboardResource($period, $topEntries, $myEntry),
        ]);
    }
}
