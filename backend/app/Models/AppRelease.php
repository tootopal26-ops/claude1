<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'platform', 'version_name', 'version_code', 'release_notes',
    'file_asset_id', 'is_current', 'uploaded_by_staff_id',
])]
class AppRelease extends Model
{
    public const array PLATFORMS = ['android'];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'is_current' => 'boolean',
            'download_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StorageAsset, $this>
     */
    public function fileAsset(): BelongsTo
    {
        return $this->belongsTo(StorageAsset::class, 'file_asset_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function uploadedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'uploaded_by_staff_id');
    }

    /**
     * @return HasMany<AppReleaseDownload, $this>
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(AppReleaseDownload::class);
    }

    /**
     * @param  Builder<AppRelease>  $query
     */
    public function scopePlatform(Builder $query, string $platform): void
    {
        $query->where('platform', $platform);
    }

    /**
     * @param  Builder<AppRelease>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }
}
