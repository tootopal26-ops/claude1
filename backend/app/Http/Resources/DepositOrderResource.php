<?php

namespace App\Http\Resources;

use App\Models\DepositOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DepositOrder */
class DepositOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (string) $this->amount,
            'status' => $this->status,
            'payment_url' => $this->status === 'pending' ? $this->payment_url : null,
            'utr' => $this->utr,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
