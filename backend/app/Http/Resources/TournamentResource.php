<?php

namespace App\Http\Resources;

use App\Models\Tournament;
use App\Models\TournamentAdTicket;
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

        $myEntry = $request->user()
            ? $this->entries()->where('user_id', $request->user()->id)->where('status', 'confirmed')->first()
            : null;
        $myResult = $myEntry?->currentResult();
        $joinClosesAt = $this->scheduled_start_at->copy()->addMinutes((int) config('minimax.tournament_join_window_minutes'));

        return [
            'id' => $this->id,
            'game_id' => $this->game_id,
            'game' => $this->whenLoaded('game', fn () => [
                'id' => $this->game->id,
                'title' => $this->game->name,
                'image_url' => $this->game->imageAsset?->publicUrl(),
                'thumbnail_url' => $this->game->thumbnailAsset?->publicUrl(),
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'rules' => $this->rules,
            'mode' => $this->mode,
            'required_player_count' => $this->requiredPlayerCount(),
            'entry_fee' => (string) $this->entry_fee,
            'per_kill_reward' => (string) $this->per_kill_reward,
            'prize_pool' => (string) $this->prize_pool,
            'total_slots' => $this->total_slots,
            'joined_slots' => $joinedSlots,
            'scheduled_start_at' => $this->scheduled_start_at->toIso8601String(),
            'status' => $this->status,
            'join_closes_at' => $joinClosesAt->toIso8601String(),
            'joining_open' => $this->isJoiningOpen($joinedSlots),
            'credentials_released' => $this->credentials_released_at !== null,
            'already_joined' => $myEntry !== null,
            // Rewarded-ad tickets this free tournament needs, and how many the player holds.
            'ad_tickets_required' => $this->isFree() ? (int) $this->required_ad_tickets : 0,
            'ad_tickets_earned' => $request->user() && $this->isFree() && $this->required_ad_tickets > 0
                ? TournamentAdTicket::where('tournament_id', $this->id)->where('user_id', $request->user()->id)->count()
                : 0,
            'my_result' => $myResult ? [
                'rank' => $myResult->rank,
                'kills' => $myResult->kills,
                'prize_awarded' => (string) $myResult->prize_awarded,
            ] : null,
        ];
    }
}
