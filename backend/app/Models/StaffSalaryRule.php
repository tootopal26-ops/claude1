<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['staff_user_id', 'action_type', 'amount_per_action', 'is_active', 'created_by_staff_id'])]
class StaffSalaryRule extends Model
{
    protected function casts(): array
    {
        return [
            'amount_per_action' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_id');
    }
}
