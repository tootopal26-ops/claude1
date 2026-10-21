<?php

namespace App\Http\Resources;

use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tournament */
class TournamentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $joinedSlots = $this->joined_slots_count ?? $this->confirmedEntriesCount();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'rules' => $this->rules,
            'mode' => $this->mode,
            'entry_fee' => (string) $this->entry_fee,
            'total_slots' => $this->total_slots,
            'joined_slots' => $joinedSlots,
            'scheduled_start_at' => $this->scheduled_start_at->toIso8601String(),
            'status' => $this->status,
            'already_joined' => $request->user()
                ? $this->entries()->where('user_id', $request->user()->id)->where('status', 'confirmed')->exists()
                : false,
        ];
    }
}
