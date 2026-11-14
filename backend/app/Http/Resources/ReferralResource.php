<?php

namespace App\Http\Resources;

use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the signed-in player's OWN referral history (as the referrer) — never shows
 * the referred player's contact details, only their username, to the person who referred
 * them.
 *
 * @mixin Referral
 */
class ReferralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'referred_username' => $this->referred?->username,
            'referrer_reward' => (string) $this->referrer_reward,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
