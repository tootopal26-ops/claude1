<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider-reported ad revenue for a period, entered by an Admin from the LevelPlay dashboard. Only finalized reports are settled.
 */
#[Fillable(['period_start', 'period_end', 'provider', 'placement_key', 'revenue_usd', 'status', 'source', 'notes', 'entered_by_staff_id'])]
class AdRevenueReport extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'revenue_usd' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'entered_by_staff_id');
    }
}
