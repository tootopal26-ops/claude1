<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['mobile', 'ip_address', 'successful'])]
class LoginAttempt extends Model
{
    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
        ];
    }
}
