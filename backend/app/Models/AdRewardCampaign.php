<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fixed-reward rewarded-ad campaign: a set number of coins per verified completion, capped per player per day and optionally by a total budget.
 */
#[Fillable(['name', 'reward_coins', 'daily_limit_per_user', 'budget_coins', 'credited_coins', 'is_active', 'starts_at', 'ends_at', 'created_by_staff_id'])]
class AdRewardCampaign extends Model
{
    protected function casts(): array
    {
        return [
            'reward_coins' => 'decimal:2',
            'budget_coins' => 'decimal:2',
            'credited_coins' => 'decimal:2',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<RewardedClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(RewardedClaim::class);
    }
}
