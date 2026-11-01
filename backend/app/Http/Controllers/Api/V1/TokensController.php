<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tokens\TokenExchangeService;
use App\Domain\Tokens\TokenService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TokenExchangeTransactionResource;
use App\Models\TokenExchangeTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The player's token balance and the fixed token-for-coins exchange tiers. Exchanging goes
 * through the same generic rewarded-ad endpoints as every other reward (AdsController,
 * purpose 'token_exchange') — there's no endpoint here that moves a balance, because an
 * exchange is never granted without a verified ad.
 */
class TokensController extends Controller
{
    public function show(Request $request, TokenService $tokens): JsonResponse
    {
        return response()->json(['data' => [
            'balance' => $tokens->getOrCreateBalance($request->user())->balance,
        ]]);
    }

    public function tiers(TokenExchangeService $exchange): JsonResponse
    {
        return response()->json(['data' => $exchange->tiers()]);
    }

    public function history(Request $request, TokenExchangeService $exchange): JsonResponse
    {
        $page = $exchange->history($request->user());

        return response()->json([
            'data' => collect($page->items())->map(fn (TokenExchangeTransaction $tx) => new TokenExchangeTransactionResource($tx))->values(),
        ]);
    }
}
