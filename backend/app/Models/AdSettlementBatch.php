<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One weekly Ads Earning settlement: the revenue it shares, the policy used (shares, exchange rate, coin conversion) and its totals. Never edited after completion; corrections are separate adjustments.
 */
#[Fillable(['period_start', 'period_end', 'status', 'eligible_revenue_usd', 'revenue_sources', 'business_share_pct', 'user_share_pct', 'business_share_usd', 'user_pool_usd', 'usd_inr_rate', 'rate_source', 'rate_at', 'user_pool_inr', 'coins_per_inr', 'policy_version', 'total_units', 'eligible_users', 'total_coins_allocated', 'total_coins_credited', 'remainder_coins', 'created_by_staff_id', 'calculated_at', 'completed_at'])]
class AdSettlementBatch extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'eligible_revenue_usd' => 'decimal:6',
            'revenue_sources' => 'array',
            'business_share_pct' => 'decimal:2',
            'user_share_pct' => 'decimal:2',
            'business_share_usd' => 'decimal:6',
            'user_pool_usd' => 'decimal:6',
            'usd_inr_rate' => 'decimal:4',
            'rate_at' => 'datetime',
            'user_pool_inr' => 'decimal:4',
            'coins_per_inr' => 'decimal:2',
            'total_coins_allocated' => 'decimal:2',
            'total_coins_credited' => 'decimal:2',
            'remainder_coins' => 'decimal:4',
            'calculated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AdSettlementAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(AdSettlementAllocation::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_id');
    }
}
