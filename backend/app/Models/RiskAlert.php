<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['type', 'risk_level', 'actor_staff_id', 'target_user_id', 'subject_type', 'subject_id', 'details'])]
class RiskAlert extends Model
{
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function actorStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'actor_staff_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function reviewedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'reviewed_by_staff_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOpen(): bool
    {
        return $this->reviewed_at === null;
    }
}
