<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type', 'title', 'body', 'data', 'audience_type', 'audience_meta',
    'created_by_staff_id', 'status', 'idempotency_key',
])]
class NotificationBatch extends Model
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'audience_meta' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_id');
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function isAdminBroadcast(): bool
    {
        return $this->type === 'admin_broadcast';
    }
}
