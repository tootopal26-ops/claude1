<?php

namespace App\Livewire\Admin;

use App\Domain\Leaderboard\LeaderboardBannerService;
use App\Domain\Media\ImageVariantProcessor;
use App\Models\LeaderboardBanner;
use App\Rules\AppLink;
use App\Support\Portal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('components.layouts.admin')]
class LeaderboardBannerForm extends Component
{
    use WithFileUploads;

    public ?LeaderboardBanner $banner = null;

    /** leaderboard | home — fixed by the route the page was opened from. */
    #[Locked]
    public string $placement = 'leaderboard';

    public string $title = '';

    public string $linkUrl = '';

    public bool $isActive = true;

    public int $displayOrder = 1;

    public int $focalX = 50;

    public int $focalY = 50;

    public ?TemporaryUploadedFile $image = null;

    /** The Home banner routes pass `placement: home` as a route default. */
    public function mount(?LeaderboardBanner $banner = null, string $placement = 'leaderboard'): void
    {
        abort_unless(in_array($placement, LeaderboardBanner::PLACEMENTS, true), 404);
        $this->placement = $placement;
        abort_unless(Auth::guard('staff')->user()->hasPermission(LeaderboardBannerList::permissionFor($this->placement)), 403);
        // A banner is edited only from the page of its own placement.
        abort_if($banner !== null && $banner->exists && $banner->placement !== $this->placement, 404);

        $this->banner = $banner;

        if ($banner !== null) {
            $this->title = (string) $banner->title;
            $this->linkUrl = (string) $banner->link_url;
            $this->isActive = $banner->is_active;
            $this->displayOrder = max(1, $banner->display_order);
            $this->focalX = $banner->focal_x;
            $this->focalY = $banner->focal_y;
        } else {
            $this->displayOrder = (int) LeaderboardBanner::query()->placement($this->placement)->max('display_order') + 1;
        }
    }

    public function updatedImage(ImageVariantProcessor $processor): void
    {
        try {
            $this->validateOnly('image', $this->imageRules());
            $processor->inspect($this->image, $this->focalX, $this->focalY);
        } catch (ValidationException $e) {
            $this->image = null;

            throw $e;
        }
    }

    public function save(LeaderboardBannerService $banners)
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission(LeaderboardBannerList::permissionFor($this->placement)), 403);

        $this->linkUrl = trim($this->linkUrl);

        $this->validate([
            'title' => ['nullable', 'string', 'max:80'],
            'linkUrl' => ['nullable', 'string', 'max:255', new AppLink],
            'isActive' => ['boolean'],
            'displayOrder' => ['required', 'integer', 'min:1', 'max:9999'],
            'focalX' => ['required', 'integer', 'between:0,100'],
            'focalY' => ['required', 'integer', 'between:0,100'],
            ...$this->imageRules(),
        ], attributes: ['displayOrder' => 'display order', 'isActive' => 'status', 'linkUrl' => 'link']);

        $banners->save($this->banner, [
            'placement' => $this->placement,
            'title' => trim($this->title) ?: null,
            'link_url' => $this->linkUrl ?: null,
            'is_active' => $this->isActive,
            'display_order' => $this->displayOrder,
            'focal_x' => $this->focalX,
            'focal_y' => $this->focalY,
        ], $this->image, $staff);

        session()->flash('status', 'Banner saved.');

        if ($this->placement === 'home') {
            return $this->redirectRoute(Portal::routeName('home-banners.index'), navigate: false);
        }

        return $this->redirectRoute(Portal::routeName('leaderboards.banners.index'), navigate: false);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function imageRules(): array
    {
        return [
            'image' => [$this->banner?->image_asset_id ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function render()
    {
        $previewUrl = null;

        if ($this->image) {
            try {
                $previewUrl = $this->image->temporaryUrl();
            } catch (\Throwable) {
                $previewUrl = null;
            }
        }

        return view('livewire.admin.leaderboard-banner-form', [
            'previewUrl' => $previewUrl,
            'currentUrl' => $this->banner?->thumbnailAsset?->publicUrl(),
        ]);
    }
}
