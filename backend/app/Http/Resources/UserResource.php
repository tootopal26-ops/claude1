<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'mobile' => $this->mobile,
            // The signed-in player's own account details (My Profile). Never sent for other players.
            'email' => $this->email,
            'state' => $this->state,
            'member_since' => $this->created_at?->toIso8601String(),
        ];
    }
}
