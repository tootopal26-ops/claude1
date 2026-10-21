<?php

namespace App\Http\Resources;

use App\Models\TournamentEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TournamentEntry */
class TournamentEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $player = $this->players->first();

        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'in_game_name' => $player?->in_game_name,
            'uid' => $player?->uid,
        ];
    }
}
