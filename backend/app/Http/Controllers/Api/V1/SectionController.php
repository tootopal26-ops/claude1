<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sections\SectionUnlockService;
use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UnlockSectionRequest;
use App\Http\Resources\WalletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    /**
     * Every registered section with its current price (null while free) and whether
     * this player has it. The price shown in the app always comes from here.
     */
    public function index(Request $request, SectionUnlockService $sections): JsonResponse
    {
        return response()->json(['data' => $sections->sectionsFor($request->user())]);
    }

    public function unlock(UnlockSectionRequest $request, string $key, SectionUnlockService $sections, WalletService $wallet): JsonResponse
    {
        $user = $request->user();
        $outcome = $sections->unlock($user, $key, (int) $request->validated('expected_price'));

        if ($outcome->succeeded()) {
            return response()->json([
                'message' => $outcome->message(),
                'data' => [
                    'section' => $sections->statusFor($user, $key),
                    'charged' => $outcome->charged,
                    'already_unlocked' => $outcome->result === 'already_unlocked',
                    'balance' => new WalletResource($wallet->getOrCreateWallet($user)->fresh()),
                ],
            ]);
        }

        $body = ['message' => $outcome->message(), 'error' => $outcome->result];

        if ($outcome->result === 'price_changed') {
            $body['price'] = $outcome->price;
        }

        if ($outcome->result === 'insufficient_balance') {
            $body['required'] = number_format($outcome->price, 2, '.', '');
            $body['available'] = $outcome->available;
        }

        return response()->json($body, match ($outcome->result) {
            'unknown_section' => 404,
            'ineligible' => 403,
            'price_changed', 'not_for_sale' => 409,
            default => 422,
        });
    }
}
