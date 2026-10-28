<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ads\AdsEarningService;
use App\Http\Controllers\Controller;
use App\Models\AdSettlementAllocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app's Ads Earning screen: status, the player's own switch, and reward history. */
class AdsEarningController extends Controller
{
    public function show(Request $request, AdsEarningService $earning): JsonResponse
    {
        return response()->json(['data' => $earning->status($request->user())]);
    }

    public function preference(Request $request, AdsEarningService $earning): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        $earning->setPreference($request->user(), (bool) $validated['enabled']);

        return response()->json(['data' => $earning->status($request->user())]);
    }

    public function history(Request $request, AdsEarningService $earning): JsonResponse
    {
        $page = $earning->history($request->user());

        return response()->json([
            'data' => collect($page->items())->map(fn (AdSettlementAllocation $allocation) => [
                'id' => $allocation->id,
                'period_start' => $allocation->batch->period_start->toIso8601String(),
                'period_end' => $allocation->batch->period_end->toIso8601String(),
                'units' => $allocation->units,
                'coins' => (string) $allocation->coins,
                // Shown as credited only once the ledger entry exists.
                'status' => $allocation->status === 'credited' && $allocation->wallet_ledger_entry_id ? 'credited' : ($allocation->status === 'below_minimum' ? 'below_minimum' : ($allocation->status === 'failed' ? 'processing' : 'pending')),
                'credited_at' => $allocation->credited_at?->toIso8601String(),
                'reference' => $allocation->wallet_ledger_entry_id ? 'L'.$allocation->wallet_ledger_entry_id : null,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
