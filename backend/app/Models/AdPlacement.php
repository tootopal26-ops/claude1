<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One place in the app an ad may appear. The catalog is fixed (seeded by migration); Admin turns each on or off and picks its ad unit.
 */
#[Fillable(['name', 'is_enabled', 'ad_unit_id', 'daily_cap_per_user', 'cooldown_seconds', 'insert_every', 'updated_by_staff_id'])]
class AdPlacement extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_supported' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AdUnit, $this>
     */
    public function adUnit(): BelongsTo
    {
        return $this->belongsTo(AdUnit::class);
    }
}
