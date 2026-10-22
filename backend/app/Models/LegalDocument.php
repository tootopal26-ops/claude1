<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'version', 'title', 'content', 'is_current', 'published_by_staff_id', 'published_at'])]
class LegalDocument extends Model
{
    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function publishedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'published_by_staff_id');
    }

    /**
     * @return HasMany<LegalAcceptance, $this>
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }
}
