<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Wallet */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'deposit_balance' => (string) $this->deposit_balance,
            'eligible_winnings_balance' => (string) $this->eligible_winnings_balance,
            'total_balance' => $this->totalBalance(),
            'withdrawable_balance' => (string) $this->eligible_winnings_balance,
        ];
    }
}
