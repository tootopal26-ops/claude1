<?php

namespace App\Http\Resources;

use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WithdrawalRequest */
class WithdrawalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (string) $this->amount,
            'upi_id' => $this->upi_id,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'failure_reason' => $this->failure_reason,
            'settlement_reference' => $this->settlement_reference,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
