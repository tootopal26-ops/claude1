<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'order_id', 'event_type', 'payload'])]
class PaymentWebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
