<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's daily login reward for one configured calendar day. The amount is drawn by the server when the day's claim starts and kept for every retry.
 */
#[Fillable(['user_id', 'reward_day', 'amount', 'status', 'wallet_ledger_entry_id', 'credited_at'])]
class DailyRewardClaim extends Model
{
    protected function casts(): array
    {
        return [
            'reward_day' => 'date',
            'amount' => 'decimal:2',
            'credited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
