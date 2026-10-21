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
        $players = collect($request->validated('players'))
            ->map(fn (array $player) => ['in_game_name' => $player['in_game_name'], 'uid' => $player['uid'] ?? null])
            ->all();

        $entry = $action->execute($tournament, $request->user(), $players);

        return response()->json([
            'data' => new TournamentEntryResource($entry),
        ], 201);
    }

    public function credentials(Request $request, Tournament $tournament): JsonResponse
    {
        $hasConfirmedEntry = $tournament->entries()
            ->where('user_id', $request->user()->id)
            ->where('status', 'confirmed')
            ->exists();

        abort_unless($hasConfirmedEntry, 403, 'You have not joined this tournament.');

        $credential = $tournament->credential;

        abort_if($credential === null, 404, 'Credentials have not been released yet.');

        return response()->json([
            'data' => [
                'room_id' => $credential->room_id,
                'room_password' => $credential->room_password,
                'released_at' => $tournament->credentials_released_at->toIso8601String(),
            ],
        ]);
    }
}
