<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A LevelPlay ad unit the app can load. Network instances (AdMob, Unity Ads) live in the LevelPlay dashboard; the reference fields only record them.
 */
#[Fillable(['name', 'format', 'environment', 'levelplay_ad_unit_id', 'admob_reference', 'unity_reference', 'is_active', 'last_loaded_at', 'last_error_at', 'last_error', 'updated_by_staff_id'])]
class AdUnit extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_loaded_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AdPlacement, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(AdPlacement::class);
    }
}
