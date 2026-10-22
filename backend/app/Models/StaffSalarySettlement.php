<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'staff_user_id', 'period_start', 'period_end', 'status', 'total_amount',
    'paid_by_staff_id', 'paid_at', 'payment_reference',
])]
class StaffSalarySettlement extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
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
    public function paidByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'paid_by_staff_id');
    }

    /**
     * @return HasMany<StaffSalarySettlementItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StaffSalarySettlementItem::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
