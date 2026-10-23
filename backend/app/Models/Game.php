<?php

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'image_path', 'display_order', 'is_active', 'focal_x', 'focal_y'])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<StorageAsset, $this>
     */
    public function imageAsset(): BelongsTo
    {
        return $this->belongsTo(StorageAsset::class, 'image_asset_id');
    }

    /**
     * @return BelongsTo<StorageAsset, $this>
     */
    public function thumbnailAsset(): BelongsTo
    {
        return $this->belongsTo(StorageAsset::class, 'thumbnail_asset_id');
    }

    /**
     * Cards the player app shows: enabled, in the order the admin set.
     *
     * @param  Builder<Game>  $query
     */
    public function scopeVisibleInApp($query): void
    {
        $query->where('is_active', true)->orderBy('display_order')->orderBy('id');
    }

    /**
     * @return HasMany<Tournament, $this>
     */
    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }
}
