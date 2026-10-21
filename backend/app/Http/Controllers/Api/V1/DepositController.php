<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\DepositService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RequestDepositRequest;
use App\Http\Resources\DepositOrderResource;
use App\Models\DepositOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $deposits = $request->user()->depositOrders()->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => DepositOrderResource::collection($deposits),
            'meta' => [
                'current_page' => $deposits->currentPage(),
                'last_page' => $deposits->lastPage(),
            ],
        ]);
    }

    public function store(RequestDepositRequest $request, DepositService $deposits): JsonResponse
    {
        $order = $deposits->initiate($request->user(), (string) $request->validated('amount'));

        return response()->json([
            'data' => new DepositOrderResource($order),
        ], 201);
    }

    /**
     * Returns the current status of one deposit, reconciling with the
     * gateway first if it's still pending — this is what the mobile app
     * polls after the player returns from the payment browser, since the
     * browser redirect itself is never trusted to confirm payment.
     */
    public function show(Request $request, DepositOrder $deposit, DepositService $deposits): JsonResponse
    {
        abort_unless($deposit->user_id === $request->user()->id, 403);

        $deposit = $deposits->reconcile($deposit);

        return response()->json([
            'data' => new DepositOrderResource($deposit),
        ]);
    }
}
