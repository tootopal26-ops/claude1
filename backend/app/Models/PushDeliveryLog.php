<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['notification_id', 'device_push_token_id', 'status', 'error_message', 'attempted_at'])]
class PushDeliveryLog extends Model
{
    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    /**
     * @return BelongsTo<DevicePushToken, $this>
     */
    public function devicePushToken(): BelongsTo
    {
        return $this->belongsTo(DevicePushToken::class);
    }
}
