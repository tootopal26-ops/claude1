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
        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'entry_fee_paid' => (string) $this->entry_fee_paid,
            'players' => $this->players->sortBy('slot_number')->values()->map(fn ($player) => [
                'slot_number' => $player->slot_number,
                'in_game_name' => $player->in_game_name,
                'uid' => $player->uid,
            ]),
        ];
    }
}
