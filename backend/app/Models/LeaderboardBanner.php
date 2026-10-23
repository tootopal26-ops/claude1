<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['placement', 'title', 'link_url', 'is_active', 'display_order', 'focal_x', 'focal_y'])]
class LeaderboardBanner extends Model
{
    /** Where in the app a banner is shown. */
    public const PLACEMENTS = ['leaderboard', 'home'];

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
     * @param  Builder<LeaderboardBanner>  $query
     */
    public function scopePlacement(Builder $query, string $placement): void
    {
        $query->where('placement', $placement);
    }

    /**
     * Banners the player app shows: enabled, with a picture, in admin order.
     *
     * @param  Builder<LeaderboardBanner>  $query
     */
    public function scopeVisibleInApp(Builder $query): void
    {
        $query->where('is_active', true)->whereNotNull('image_asset_id')->orderBy('display_order')->orderBy('id');
    }
}
