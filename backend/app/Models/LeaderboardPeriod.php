<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'period_start', 'period_end', 'status', 'finalized_at'])]
class LeaderboardPeriod extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LeaderboardEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LeaderboardEntry::class);
    }

    /**
     * @return HasMany<LeaderboardRewardPayout, $this>
     */
    public function rewardPayouts(): HasMany
    {
        return $this->hasMany(LeaderboardRewardPayout::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }
}
