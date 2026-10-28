<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audited correction to a credited Ads Earning allocation: signed coins, a required reason, and its own wallet ledger entry.
 */
#[Fillable(['ad_settlement_allocation_id', 'user_id', 'coins', 'reason', 'wallet_ledger_entry_id', 'created_by_staff_id'])]
class AdSettlementAdjustment extends Model
{
    protected function casts(): array
    {
        return [
            'coins' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<AdSettlementAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(AdSettlementAllocation::class, 'ad_settlement_allocation_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_id');
    }
}
