<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ads\DailyRewardService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The daily login reward: whether to offer the popup, "don't show again today", and (only
 * when Admin has turned the ad requirement off) claiming without an ad. With the ad
 * requirement on, the reward is credited only by the ad network's verified callback.
 */
class DailyRewardController extends Controller
{
    public function show(Request $request, DailyRewardService $daily): JsonResponse
    {
        return response()->json(['data' => $daily->status($request->user())]);
    }

    public function dismiss(Request $request, DailyRewardService $daily): JsonResponse
    {
        $daily->dismissToday($request->user());

        return response()->json(['data' => $daily->status($request->user())]);
    }

    public function claim(Request $request, DailyRewardService $daily, WalletService $wallet): JsonResponse
    {
        $claim = $daily->claimWithoutAd($request->user());

        return response()->json(['data' => [
            'amount' => (string) $claim->amount,
            'status' => $daily->status($request->user()),
            'balance' => new WalletResource($wallet->getOrCreateWallet($request->user())->fresh()),
        ]]);
    }
}
