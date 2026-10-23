<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tournament\ConfirmSlotBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\JoinTournamentRequest;
use App\Http\Resources\TournamentEntryResource;
use App\Http\Resources\TournamentResource;
use App\Models\Tournament;
use App\Models\TournamentResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentController extends Controller
{
    /**
     * The app's tournament tabs: Upcoming, Ongoing and Completed (which also holds
     * cancelled tournaments, labelled as such). `meta.counts` gives every tab's size for
     * the same game filter, so the Home shortcuts and the tab badges come from the server.
     */
    public const STATUS_GROUPS = [
        'upcoming' => ['upcoming'],
        'ongoing' => ['ongoing'],
        'completed' => ['completed', 'cancelled'],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::STATUS_GROUPS))],
            'game_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $base = Tournament::query()
            ->when(isset($validated['game_id']), fn ($query) => $query->where('game_id', (int) $validated['game_id']));

        $byStatus = (clone $base)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $counts = collect(self::STATUS_GROUPS)->map(fn (array $statuses) => (int) collect($statuses)->sum(fn (string $status) => $byStatus[$status] ?? 0))->all();

        $status = $validated['status'] ?? null;

        $tournaments = (clone $base)
            ->with(['game.imageAsset', 'game.thumbnailAsset'])
            ->withCount(['entries as joined_slots_count' => fn ($query) => $query->where('status', 'confirmed')])
            ->when($status !== null, fn ($query) => $query->whereIn('status', self::STATUS_GROUPS[$status]))
            ->when($status === 'completed', fn ($query) => $query->orderByDesc('scheduled_start_at'), fn ($query) => $query->orderBy('scheduled_start_at'))
            ->get();

        return response()->json([
            'data' => TournamentResource::collection($tournaments),
            'meta' => ['counts' => $counts],
        ]);
    }

    public function show(Request $request, Tournament $tournament): JsonResponse
    {
        $tournament->load(['game.imageAsset', 'game.thumbnailAsset'])
            ->loadCount(['entries as joined_slots_count' => fn ($query) => $query->where('status', 'confirmed')]);

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

    /**
     * The final standings of a finished tournament: current (never superseded) results
     * only, best rank first. Nothing is returned until results have been published.
     */
    public function results(Tournament $tournament): JsonResponse
    {
        $results = TournamentResult::query()
            ->where('tournament_id', $tournament->id)
            ->whereNull('superseded_by_id')
            ->with(['entry.user', 'entry.players'])
            ->orderBy('rank')
            ->get();

        return response()->json([
            'data' => [
                'tournament_id' => $tournament->id,
                'status' => $tournament->status,
                'published' => $results->isNotEmpty(),
                'results' => $results->map(fn (TournamentResult $result) => [
                    'rank' => $result->rank,
                    'user_id' => $result->entry->user_id,
                    'username' => $result->entry->user->username,
                    'players' => $result->entry->players->sortBy('slot_number')->map(fn ($player) => [
                        'slot_number' => $player->slot_number,
                        'in_game_name' => $player->in_game_name,
                    ])->values()->all(),
                    'kills' => $result->kills,
                    'prize_awarded' => (string) $result->prize_awarded,
                ])->all(),
            ],
        ]);
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
