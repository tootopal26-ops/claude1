<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tournament\ConfirmSlotBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\JoinTournamentRequest;
use App\Http\Resources\TournamentEntryResource;
use App\Http\Resources\TournamentResource;
use App\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tournaments = Tournament::query()
            ->withCount(['entries as joined_slots_count' => fn ($query) => $query->where('status', 'confirmed')])
            ->orderBy('scheduled_start_at')
            ->get();

        return response()->json([
            'data' => TournamentResource::collection($tournaments),
        ]);
    }

    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        $tournament->loadCount(['entries as joined_slots_count' => fn ($query) => $query->where('status', 'confirmed')]);

        return response()->json([
            'data' => new TournamentResource($tournament),
        ]);
    }

    public function join(JoinTournamentRequest $request, Tournament $tournament, ConfirmSlotBookingAction $action): JsonResponse
    {
        $entry = $action->execute(
            $tournament,
            $request->user(),
            $request->string('in_game_name')->toString(),
            $request->string('uid')->toString() ?: null,
        );

        return response()->json([
            'data' => new TournamentEntryResource($entry),
        ], 201);
    }
}
