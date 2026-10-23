<?php

namespace App\Domain\Media;

use App\Domain\Storage\StorageService;
use App\Models\StaffUser;
use App\Models\StorageAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Stores and discards the processed 16:9 artwork shared by every image-backed
 * admin feature (game cards, leaderboard banners). Callers keep the two asset
 * ids on their own record; this class only knows how to turn an upload into
 * two storage assets and how to remove assets that nothing references.
 */
final class ManagedImageService
{
    public function __construct(
        private readonly ImageVariantProcessor $processor,
        private readonly StorageService $storage,
    ) {}

    /**
     * @return array{image: StorageAsset, thumbnail: StorageAsset}
     *
     * @throws ValidationException
     */
    public function store(UploadedFile $file, string $bucket, int $focalX, int $focalY, ?StaffUser $actor, ?Model $subject = null): array
    {
        $variants = $this->processor->process($file, $focalX, $focalY);
        $label = $file->getClientOriginalName();

        $image = $this->storage->storeContents($variants['main'], $variants['mime'], $bucket, $label, $subject, $actor);

        try {
            $thumbnail = $this->storage->storeContents($variants['thumb'], $variants['mime'], $bucket, 'thumb-'.$label, $subject, $actor);
        } catch (\Throwable $e) {
            $this->storage->delete($image, $actor);

            throw $e;
        }

        return ['image' => $image, 'thumbnail' => $thumbnail];
    }

    /**
     * Removes assets a record no longer points at. Call only after the record
     * has been saved without them, so a failed save never leaves a record
     * pointing at a deleted file.
     */
    public function discard(?StaffUser $actor, StorageAsset|int|null ...$assets): void
    {
        foreach ($assets as $asset) {
            $asset = is_int($asset) ? StorageAsset::find($asset) : $asset;

            if ($asset !== null) {
                $this->storage->delete($asset, $actor);
            }
        }
    }
}
