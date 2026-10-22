<?php

namespace App\Domain\Storage;

use App\Models\AuditLog;
use App\Models\RiskAlert;
use App\Models\StaffUser;
use App\Models\StorageAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The single choke point for every uploaded file in the platform: every
 * store/delete/retain decision goes through here so a bucket's validation
 * rules and the dispute/audit retention exemption apply identically no
 * matter which feature is uploading.
 *
 * Files are stored under an opaque, randomly generated name — the
 * original filename is kept only as a display label on the record, never
 * used to build the on-disk path — so a crafted filename (path
 * traversal, a double extension, control characters) has no path to
 * reach the filesystem. The MIME type checked against a bucket's
 * allow-list is the one PHP's fileinfo extension detects from the
 * file's actual bytes (UploadedFile::getMimeType()), never the
 * client-supplied Content-Type header or the filename's extension.
 */
class StorageService
{
    private const string DISK = 'local';

    private const array EXTENSIONS_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * @throws ValidationException
     */
    public function store(UploadedFile $file, string $bucket, ?Model $subject = null, ?StaffUser $uploadedBy = null): StorageAsset
    {
        $config = $this->bucketConfig($bucket);

        $mimeType = $file->getMimeType();

        if ($mimeType === null || ! in_array($mimeType, $config['allowed_mime_types'], true)) {
            throw ValidationException::withMessages([
                'file' => "This file type isn't allowed for {$config['label']}. Allowed: ".implode(', ', $config['allowed_mime_types']).'.',
            ]);
        }

        $sizeBytes = $file->getSize();
        $maxBytes = $config['max_size_kb'] * 1024;

        if ($sizeBytes > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => "File is too large for {$config['label']} (max {$config['max_size_kb']} KB).",
            ]);
        }

        $storedName = Str::uuid()->toString().'.'.self::EXTENSIONS_BY_MIME[$mimeType];

        $path = $file->storeAs("storage_assets/{$bucket}", $storedName, self::DISK);

        return StorageAsset::create([
            'bucket' => $bucket,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'uploaded_by_staff_id' => $uploadedBy?->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_filename' => $this->sanitizeFilename($file->getClientOriginalName()),
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function retain(StorageAsset $asset, string $reason, StaffUser $actor): StorageAsset
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required to retain a storage asset.',
            ]);
        }

        $asset->retained_reason = $reason;
        $asset->save();

        AuditLog::record('storage.asset_retained', $actor, $asset, ['reason' => $reason]);

        return $asset;
    }

    public function unretain(StorageAsset $asset, StaffUser $actor): StorageAsset
    {
        $asset->retained_reason = null;
        $asset->save();

        AuditLog::record('storage.asset_unretained', $actor, $asset);

        return $asset;
    }

    public function delete(StorageAsset $asset, ?StaffUser $actor = null): void
    {
        Storage::disk($asset->disk)->delete($asset->path);

        AuditLog::record('storage.asset_deleted', $actor, $asset, [
            'bucket' => $asset->bucket,
            'original_filename' => $asset->original_filename,
        ]);

        $asset->delete();
    }

    /**
     * An asset is protected from automated cleanup when its subject (the
     * tournament, withdrawal, etc. it's evidence for) is the subject of
     * a risk alert nobody has reviewed yet.
     */
    public function isProtectedByOpenRiskAlert(StorageAsset $asset): bool
    {
        if ($asset->subject_type === null) {
            return false;
        }

        return RiskAlert::query()
            ->where('subject_type', $asset->subject_type)
            ->where('subject_id', $asset->subject_id)
            ->whereNull('reviewed_at')
            ->exists();
    }

    /**
     * @return Collection<int, array{bucket: string, label: string, count: int, total_bytes: int, quota_bytes: int}>
     */
    public function usageByBucket(): Collection
    {
        $totals = StorageAsset::query()
            ->selectRaw('bucket, COUNT(*) as count, SUM(size_bytes) as total_bytes')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $quotaBytes = (int) config('minimax.storage_quota_bytes_per_bucket');

        return collect(config('minimax.storage_buckets'))
            ->map(function (array $bucketConfig, string $bucket) use ($totals, $quotaBytes) {
                $row = $totals->get($bucket);

                return [
                    'bucket' => $bucket,
                    'label' => $bucketConfig['label'],
                    'count' => (int) ($row->count ?? 0),
                    'total_bytes' => (int) ($row->total_bytes ?? 0),
                    'quota_bytes' => $quotaBytes,
                ];
            })
            ->values();
    }

    /**
     * @return array{label: string, max_size_kb: int, allowed_mime_types: list<string>, retention_days: int|null}
     *
     * @throws ValidationException
     */
    private function bucketConfig(string $bucket): array
    {
        $buckets = config('minimax.storage_buckets');

        if (! isset($buckets[$bucket])) {
            throw ValidationException::withMessages([
                'bucket' => "Unknown storage bucket \"{$bucket}\".",
            ]);
        }

        return $buckets[$bucket];
    }

    private function sanitizeFilename(string $filename): string
    {
        $safe = trim(preg_replace('/[^A-Za-z0-9 ._-]/', '', basename($filename)) ?? '');

        return $safe === '' ? 'upload' : substr($safe, 0, 255);
    }
}
