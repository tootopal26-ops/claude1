<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['key', 'value', 'updated_by_staff_id'])]
class PlatformSetting extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function updatedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'updated_by_staff_id');
    }
}
