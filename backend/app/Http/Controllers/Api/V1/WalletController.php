<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Wallet\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletLedgerEntryResource;
use App\Http\Resources\WalletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request, WalletService $wallet): JsonResponse
    {
        return response()->json([
            'data' => new WalletResource($wallet->getOrCreateWallet($request->user())),
        ]);
    }

    public function ledger(Request $request, WalletService $wallet): JsonResponse
    {
        $entries = $wallet->getOrCreateWallet($request->user())
            ->ledgerEntries()
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => WalletLedgerEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
            ],
        ]);
    }
}
