<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['staff_salary_settlement_id', 'staff_salary_event_id', 'amount'])]
class StaffSalarySettlementItem extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<StaffSalarySettlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(StaffSalarySettlement::class, 'staff_salary_settlement_id');
    }

    /**
     * @return BelongsTo<StaffSalaryEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(StaffSalaryEvent::class, 'staff_salary_event_id');
    }
}
