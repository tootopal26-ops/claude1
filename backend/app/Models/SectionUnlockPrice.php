<?php

namespace App\Models;

use Database\Factories\SectionUnlockPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['section_key', 'price_coins', 'is_enabled', 'updated_by_staff_id'])]
class SectionUnlockPrice extends Model
{
    /** @use HasFactory<SectionUnlockPriceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price_coins' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'updated_by_staff_id');
    }
}
