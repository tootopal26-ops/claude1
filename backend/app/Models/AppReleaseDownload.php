<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per completed public download of an AppRelease — unauthenticated by nature (the
 * player hasn't signed in yet when fetching the APK), so this carries no user reference, only
 * what the admin analytics view needs: when, and a coarse client fingerprint.
 */
#[Fillable(['app_release_id', 'ip_address', 'user_agent', 'downloaded_at'])]
class AppReleaseDownload extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppRelease, $this>
     */
    public function appRelease(): BelongsTo
    {
        return $this->belongsTo(AppRelease::class);
    }
}
