<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Referrals\ReferralService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReferralResource;
use App\Models\Referral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in player's own referral code, stats and history. Nothing here ever moves a
 * balance — that only ever happens inside ReferralService::applyReferral, called from
 * AuthController::register.
 */
class ReferralController extends Controller
{
    public function me(Request $request, ReferralService $referrals): JsonResponse
    {
        return response()->json([
            'data' => [
                'enabled' => $referrals->enabled(),
                ...$referrals->stats($request->user()),
            ],
        ]);
    }

    public function history(Request $request, ReferralService $referrals): JsonResponse
    {
        $page = $referrals->history($request->user());

        return response()->json([
            'data' => collect($page->items())->map(fn (Referral $referral) => new ReferralResource($referral))->values(),
        ]);
    }
}
