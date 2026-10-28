<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's share of one settlement, credited to their Deposit balance exactly once.
 */
#[Fillable(['ad_settlement_batch_id', 'user_id', 'units', 'coins', 'status', 'wallet_ledger_entry_id', 'attempts', 'failure_reason', 'credited_at'])]
class AdSettlementAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'coins' => 'decimal:2',
            'credited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AdSettlementBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AdSettlementBatch::class, 'ad_settlement_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
