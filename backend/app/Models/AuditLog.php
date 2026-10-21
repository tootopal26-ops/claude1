<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address'])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function actorLabel(): string
    {
        return match (true) {
            $this->actor instanceof StaffUser => "{$this->actor->display_name} (staff)",
            $this->actor instanceof User => "{$this->actor->username} (player)",
            default => 'System',
        };
    }

    public function subjectLabel(): string
    {
        if ($this->subject_type === null) {
            return '—';
        }

        $shortType = class_basename($this->subject_type);

        return "{$shortType} #{$this->subject_id}";
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
