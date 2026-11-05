<?php

namespace App\Domain\Releases;

use App\Domain\Storage\StorageService;
use App\Models\AppRelease;
use App\Models\AppReleaseDownload;
use App\Models\AuditLog;
use App\Models\StaffUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin operations on Android build releases. The project is distributed privately (no Play
 * Store), so this page — not a store listing — is the only place a build ever becomes
 * reachable by players: upload, mark current, and the public, unauthenticated download
 * endpoint always serves whichever release is current for its platform.
 */
class AppReleaseService
{
    public function __construct(private readonly StorageService $storage) {}

    /**
     * @throws ValidationException
     */
    public function publish(UploadedFile $file, string $platform, string $versionName, int $versionCode, ?string $releaseNotes, bool $makeCurrent, StaffUser $actor): AppRelease
    {
        if (AppRelease::query()->platform($platform)->where('version_code', $versionCode)->exists()) {
            throw ValidationException::withMessages([
                'versionCode' => 'A release with this version code already exists for this platform.',
            ]);
        }

        return DB::transaction(function () use ($file, $platform, $versionName, $versionCode, $releaseNotes, $makeCurrent, $actor) {
            $asset = $this->storage->store($file, 'app_releases', null, $actor);

            $release = AppRelease::create([
                'platform' => $platform,
                'version_name' => $versionName,
                'version_code' => $versionCode,
                'release_notes' => $releaseNotes,
                'file_asset_id' => $asset->id,
                'uploaded_by_staff_id' => $actor->id,
            ]);

            AuditLog::record('app_release.published', $actor, $release, [
                'platform' => $platform,
                'version_name' => $versionName,
                'version_code' => $versionCode,
            ]);

            if ($makeCurrent) {
                $this->setCurrent($release, $actor);
            }

            return $release;
        });
    }

    public function setCurrent(AppRelease $release, StaffUser $actor): void
    {
        DB::transaction(function () use ($release) {
            AppRelease::query()
                ->platform($release->platform)
                ->where('id', '!=', $release->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $release->update(['is_current' => true]);
        });

        AuditLog::record('app_release.set_current', $actor, $release, [
            'platform' => $release->platform,
            'version_name' => $release->version_name,
            'version_code' => $release->version_code,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function delete(AppRelease $release, StaffUser $actor): void
    {
        if ($release->is_current) {
            throw ValidationException::withMessages([
                'release' => 'Mark a different release current before deleting this one.',
            ]);
        }

        $asset = $release->fileAsset;
        $id = $release->id;

        DB::transaction(fn () => $release->delete());

        if ($asset !== null) {
            $this->storage->delete($asset, $actor);
        }

        AuditLog::record('app_release.deleted', $actor, null, [
            'id' => $id,
            'platform' => $release->platform,
            'version_name' => $release->version_name,
            'version_code' => $release->version_code,
        ]);
    }

    /**
     * Records one public download. Not audit-logged (no staff actor — a player hasn't signed
     * in yet) and never allowed to fail the download itself if logging has a problem.
     */
    public function recordDownload(AppRelease $release, ?string $ip, ?string $userAgent): void
    {
        DB::transaction(function () use ($release, $ip, $userAgent) {
            AppReleaseDownload::create([
                'app_release_id' => $release->id,
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
                'downloaded_at' => now(),
            ]);

            $release->increment('download_count');
        });
    }
}
