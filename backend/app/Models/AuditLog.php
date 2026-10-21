<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address'])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public static function record(
        string $action,
        ?Model $actor = null,
        ?Model $subject = null,
        array $metadata = [],
        ?string $ipAddress = null,
    ): self {
        return self::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
        ]);
    }
}
