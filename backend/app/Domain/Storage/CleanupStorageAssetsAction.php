<?php

namespace App\Domain\Storage;

use App\Models\StorageAsset;
use Illuminate\Support\Carbon;

/**
 * Removes storage assets whose bucket has a retention window once that
 * window has passed, skipping anything explicitly retained or tied to
 * the subject of a risk alert nobody has reviewed yet. A bucket with no
 * configured retention_days (e.g. staff_documents) is never touched
 * here. Safe to rerun: an asset already deleted just isn't found again.
 */
class CleanupStorageAssetsAction
{
    public function __construct(private readonly StorageService $storage) {}

    public function execute(): int
    {
        $deleted = 0;

        foreach (config('minimax.storage_buckets') as $bucket => $config) {
            if ($config['retention_days'] === null) {
                continue;
            }

            $cutoff = Carbon::now()->subDays($config['retention_days']);

            StorageAsset::query()
                ->where('bucket', $bucket)
                ->whereNull('retained_reason')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->chunkById(200, function ($assets) use (&$deleted) {
                    foreach ($assets as $asset) {
                        if ($this->storage->isProtectedByOpenRiskAlert($asset)) {
                            continue;
                        }

                        $this->storage->delete($asset);
                        $deleted++;
                    }
                });
        }

        return $deleted;
    }
}
