<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'bucket', 'subject_type', 'subject_id', 'uploaded_by_staff_id',
    'disk', 'path', 'original_filename', 'mime_type', 'size_bytes', 'retained_reason',
])]
class StorageAsset extends Model
{
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<StaffUser, $this>
     */
    public function uploadedByStaff(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'uploaded_by_staff_id');
    }

    public function isRetained(): bool
    {
        return $this->retained_reason !== null;
    }

    public function subjectLabel(): string
    {
        if ($this->subject_type === null) {
            return '—';
        }

        return class_basename($this->subject_type)." #{$this->subject_id}";
    }
}
