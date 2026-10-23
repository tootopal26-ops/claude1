<?php

namespace App\Domain\Leaderboard;

use App\Domain\Media\ManagedImageService;
use App\Models\AuditLog;
use App\Models\LeaderboardBanner;
use App\Models\StaffUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin operations on the leaderboard carousel banners. Same guarantees as the
 * game cards: one transaction plus an audit entry per change, images written
 * before the record points at them, and old files removed only after the record
 * has been saved without them.
 */
class LeaderboardBannerService
{
    public function __construct(private readonly ManagedImageService $images) {}

    /**
     * @param  array{placement?: string, title: ?string, link_url?: ?string, is_active: bool, display_order: int, focal_x: int, focal_y: int}  $data
     *
     * @throws ValidationException
     */
    public function save(?LeaderboardBanner $banner, array $data, ?UploadedFile $image, StaffUser $actor): LeaderboardBanner
    {
        $isNew = $banner === null;
        // A banner keeps the placement it was created for.
        $banner ??= new LeaderboardBanner(['placement' => $data['placement'] ?? 'leaderboard']);
        $stored = null;
        $replaced = [];

        try {
            DB::transaction(function () use ($banner, $data, $image, $actor, &$stored, &$replaced) {
                $banner->fill([
                    'title' => $data['title'] ?: null,
                    'link_url' => ($data['link_url'] ?? null) ?: null,
                    'is_active' => $data['is_active'],
                    'display_order' => $data['display_order'],
                    'focal_x' => $data['focal_x'],
                    'focal_y' => $data['focal_y'],
                ])->save();

                if ($image !== null) {
                    $stored = $this->images->store($image, 'leaderboard_banners', $data['focal_x'], $data['focal_y'], $actor, $banner);
                    $replaced = [$banner->image_asset_id, $banner->thumbnail_asset_id];

                    $banner->image_asset_id = $stored['image']->id;
                    $banner->thumbnail_asset_id = $stored['thumbnail']->id;
                    $banner->save();
                }
            });
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $this->images->discard($actor, $stored['image'], $stored['thumbnail']);
            }

            throw $e;
        }

        $this->images->discard($actor, ...array_filter($replaced));

        AuditLog::record($isNew ? 'leaderboard_banner.created' : 'leaderboard_banner.updated', $actor, $banner, [
            'placement' => $banner->placement,
            'link_url' => $banner->link_url,
            'is_active' => $banner->is_active,
            'display_order' => $banner->display_order,
            'image_replaced' => $image !== null,
        ]);

        return $banner;
    }

    public function delete(LeaderboardBanner $banner, StaffUser $actor): void
    {
        $assets = array_filter([$banner->image_asset_id, $banner->thumbnail_asset_id]);
        $id = $banner->id;

        DB::transaction(fn () => $banner->delete());

        $this->images->discard($actor, ...$assets);

        AuditLog::record('leaderboard_banner.deleted', $actor, null, ['id' => $id, 'placement' => $banner->placement]);
    }

    public function setActive(LeaderboardBanner $banner, bool $active, StaffUser $actor): void
    {
        $banner->update(['is_active' => $active]);

        AuditLog::record($active ? 'leaderboard_banner.enabled' : 'leaderboard_banner.disabled', $actor, $banner);
    }

    public function move(LeaderboardBanner $banner, int $direction, StaffUser $actor): void
    {
        DB::transaction(function () use ($banner, $direction, $actor) {
            $ordered = LeaderboardBanner::query()->placement($banner->placement)->orderBy('display_order')->orderBy('id')->lockForUpdate()->get()->values();
            $index = $ordered->search(fn (LeaderboardBanner $candidate) => $candidate->id === $banner->id);
            $target = $index === false ? false : $index + $direction;

            if ($index === false || $target < 0 || $target >= $ordered->count()) {
                return;
            }

            $list = $ordered->all();
            [$list[$index], $list[$target]] = [$list[$target], $list[$index]];

            foreach (array_values($list) as $position => $item) {
                if ($item->display_order !== $position + 1) {
                    $item->update(['display_order' => $position + 1]);
                }
            }

            AuditLog::record('leaderboard_banner.reordered', $actor, $banner, ['direction' => $direction > 0 ? 'down' : 'up']);
        });
    }
}
