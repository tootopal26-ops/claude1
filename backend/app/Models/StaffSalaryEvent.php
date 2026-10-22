<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['staff_user_id', 'staff_salary_rule_id', 'action_type', 'source_type', 'source_id', 'amount', 'occurred_at'])]
class StaffSalaryEvent extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
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
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasOne<StaffSalarySettlementItem, $this>
     */
    public function settlementItem(): HasOne
    {
        return $this->hasOne(StaffSalarySettlementItem::class);
    }

    public function isSettled(): bool
    {
        return $this->settlementItem !== null;
    }
}
