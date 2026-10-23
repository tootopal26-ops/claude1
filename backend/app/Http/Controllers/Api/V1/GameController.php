<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\JsonResponse;

class GameController extends Controller
{
    /**
     * The home-screen game cards: enabled cards only, in the admin's order.
     */
    public function index(): JsonResponse
    {
        $games = Game::query()
            ->visibleInApp()
            ->with(['imageAsset', 'thumbnailAsset'])
            ->withCount(['tournaments as upcoming_tournaments_count' => fn ($query) => $query->where('status', 'upcoming')])
            ->get();

        return response()->json([
            'data' => GameResource::collection($games),
        ]);
    }
}
