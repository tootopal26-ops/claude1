<?php

namespace App\Http\Resources;

use App\Models\TokenExchangeTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TokenExchangeTransaction */
class TokenExchangeTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tier_index' => $this->tier_index,
            'tokens_required' => $this->tokens_required,
            'coins_received' => (string) $this->coins_received,
            'bonus_percent' => (string) $this->bonus_percent,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
