<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['period_type', 'is_enabled', 'updated_by_staff_id'])]
class LeaderboardPrizeRule extends Model
{
    public const MAX_RANK = 100;

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LeaderboardPrizeRank, $this>
     */
    public function ranks(): HasMany
    {
        return $this->hasMany(LeaderboardPrizeRank::class)->orderBy('rank');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'updated_by_staff_id');
    }

    /**
     * Coins per rank, only for ranks that actually pay.
     *
     * @return array<int, int>
     */
    public function coinsByRank(): array
    {
        return $this->ranks()->where('coins', '>', 0)->pluck('coins', 'rank')->map(fn ($coins) => (int) $coins)->all();
    }
}
