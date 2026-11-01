<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Spin\SpinService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The spin wheel's status: whether it's running, the token balance, and the fixed segment
 * layout the app animates to. Claiming and verifying a spin goes through the same generic
 * rewarded-ad endpoints as every other reward (AdsController) — there's nothing to claim
 * here, because a spin is never granted without a verified ad.
 */
class SpinController extends Controller
{
    public function show(Request $request, SpinService $spin): JsonResponse
    {
        return response()->json(['data' => $spin->status($request->user())]);
    }
}
