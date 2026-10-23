<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Redeem\RedeemCodeService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RedeemCodeRequest;
use App\Http\Resources\WalletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

class RedeemController extends Controller
{
    /** Wrong codes allowed per player in the window before further attempts are refused. */
    private const MAX_STRIKES = 10;

    private const STRIKE_WINDOW_SECONDS = 900;

    public function store(RedeemCodeRequest $request, RedeemCodeService $redeem, WalletService $wallet): JsonResponse
    {
        $user = $request->user();
        $strikeKey = 'redeem-strikes:'.$user->id;

        if (RateLimiter::tooManyAttempts($strikeKey, self::MAX_STRIKES)) {
            $seconds = RateLimiter::availableIn($strikeKey);

            return response()->json([
                'message' => 'Too many incorrect codes. Try again in '.max(1, (int) ceil($seconds / 60)).' minute(s).',
                'error' => 'too_many_attempts',
                'retry_after' => $seconds,
            ], 429, ['Retry-After' => $seconds]);
        }

        $outcome = $redeem->redeem($user, (string) $request->validated('code'), $request->ip());

        if ($outcome->succeeded()) {
            return response()->json([
                'message' => $outcome->message(),
                'data' => [
                    'coins' => $outcome->coins,
                    'balance' => new WalletResource($wallet->getOrCreateWallet($user)->fresh()),
                ],
            ]);
        }

        // Asking for a code you already hold is not guessing; every other miss counts.
        if ($outcome->result !== 'already_redeemed') {
            RateLimiter::hit($strikeKey, self::STRIKE_WINDOW_SECONDS);
        }

        return response()->json([
            'message' => $outcome->message(),
            'error' => $outcome->result,
        ], $outcome->result === 'ineligible' ? 403 : 422);
    }
}
