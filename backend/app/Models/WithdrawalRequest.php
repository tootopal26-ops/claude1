<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'amount', 'upi_id', 'status',
    'reviewed_by_staff_id', 'reviewed_at', 'rejection_reason', 'failure_reason',
    'settlement_reference', 'settled_by_staff_id', 'settled_at',
])]
class WithdrawalRequest extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function reviewedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'reviewed_by_staff_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function settledByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'settled_by_staff_id');
    }
}
