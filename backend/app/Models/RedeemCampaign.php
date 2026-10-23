<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'total_capacity', 'reward_coins', 'expires_at', 'is_active', 'created_by_staff_id'])]
class RedeemCampaign extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'archived_at' => 'datetime',
            'is_active' => 'boolean',
            'total_capacity' => 'integer',
            'reward_coins' => 'integer',
            'redeemed_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<RedeemRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(RedeemRedemption::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_id');
    }

    public function remaining(): int
    {
        return max(0, $this->total_capacity - $this->redeemed_count);
    }

    /** What redeeming every seat would credit: a planning figure, not coins already credited. */
    public function potentialLiability(): int
    {
        return $this->total_capacity * $this->reward_coins;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    /**
     * active | inactive | expired | exhausted | archived — the single status the admin sees.
     */
    public function status(): string
    {
        return match (true) {
            $this->isArchived() => 'archived',
            ! $this->is_active => 'inactive',
            $this->hasExpired() => 'expired',
            $this->remaining() === 0 => 'exhausted',
            default => 'active',
        };
    }
}
