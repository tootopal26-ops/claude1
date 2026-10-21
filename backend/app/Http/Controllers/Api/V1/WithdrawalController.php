<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Withdrawal\WithdrawalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RequestWithdrawalRequest;
use App\Http\Resources\WithdrawalRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $withdrawals = $request->user()->withdrawalRequests()->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'data' => WithdrawalRequestResource::collection($withdrawals),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
            ],
        ]);
    }

    public function store(RequestWithdrawalRequest $request, WithdrawalService $withdrawals): JsonResponse
    {
        $withdrawal = $withdrawals->request(
            $request->user(),
            (string) $request->validated('amount'),
            $request->string('upi_id')->toString(),
        );

        return response()->json([
            'data' => new WithdrawalRequestResource($withdrawal),
        ], 201);
    }
}
