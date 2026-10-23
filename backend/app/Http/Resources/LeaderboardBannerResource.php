<?php

namespace App\Http\Resources;

use App\Models\LeaderboardBanner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaderboardBanner */
class LeaderboardBannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'image_url' => $this->imageAsset?->publicUrl(),
            'thumbnail_url' => $this->thumbnailAsset?->publicUrl(),
            'display_order' => $this->display_order,
        ];
    }
}
