<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Operational, non-secret settings for a payment provider — which one is
 * active and the webhook signature header name. Actual credentials (API
 * key/secret, webhook secret) are never stored here; they live only in
 * server environment variables (see config/services.php).
 */
#[Fillable(['provider', 'is_active', 'webhook_signature_header'])]
class PaymentProviderConfig extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
