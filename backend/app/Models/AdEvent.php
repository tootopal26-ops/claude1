<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Client-reported SDK telemetry (requests, loads, no-fills, impressions, the SDK's revenue estimate). Unverified: reports only, never rewards.
 */
#[Fillable(['user_id', 'placement_key', 'event', 'ad_network', 'ad_unit_id', 'revenue_usd', 'revenue_precision', 'error_code', 'is_test', 'occurred_at'])]
class AdEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'revenue_usd' => 'decimal:8',
            'is_test' => 'boolean',
            'occurred_at' => 'datetime',
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
