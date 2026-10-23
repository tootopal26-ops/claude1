<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Game */
class GameResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->name,
            'description' => $this->description,
            // Both are always 16:9; null only for a card that has no picture yet.
            'image_url' => $this->imageAsset?->publicUrl(),
            'thumbnail_url' => $this->thumbnailAsset?->publicUrl(),
            'display_order' => $this->display_order,
            // Tournaments of this game still open to join or about to start — not players, not all tournaments.
            'upcoming_tournaments_count' => (int) ($this->upcoming_tournaments_count ?? 0),
        ];
    }
}
