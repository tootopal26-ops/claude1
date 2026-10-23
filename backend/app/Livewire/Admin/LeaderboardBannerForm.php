<?php

namespace App\Livewire\Admin;

use App\Domain\Leaderboard\LeaderboardBannerService;
use App\Domain\Media\ImageVariantProcessor;
use App\Models\LeaderboardBanner;
use App\Support\Portal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('components.layouts.admin')]
class LeaderboardBannerForm extends Component
{
    use WithFileUploads;

    public ?LeaderboardBanner $banner = null;

    public string $title = '';

    public bool $isActive = true;

    public int $displayOrder = 1;

    public int $focalX = 50;

    public int $focalY = 50;

    public ?TemporaryUploadedFile $image = null;

    public function mount(?LeaderboardBanner $banner = null): void
    {
        abort_unless(Auth::guard('staff')->user()->hasPermission('leaderboards.manage'), 403);

        $this->banner = $banner;

        if ($banner !== null) {
            $this->title = (string) $banner->title;
            $this->isActive = $banner->is_active;
            $this->displayOrder = max(1, $banner->display_order);
            $this->focalX = $banner->focal_x;
            $this->focalY = $banner->focal_y;
        } else {
            $this->displayOrder = (int) LeaderboardBanner::max('display_order') + 1;
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
        abort_unless($staff->hasPermission('leaderboards.manage'), 403);

        $this->validate([
            'title' => ['nullable', 'string', 'max:80'],
            'isActive' => ['boolean'],
            'displayOrder' => ['required', 'integer', 'min:1', 'max:9999'],
            'focalX' => ['required', 'integer', 'between:0,100'],
            'focalY' => ['required', 'integer', 'between:0,100'],
            ...$this->imageRules(),
        ], attributes: ['displayOrder' => 'display order', 'isActive' => 'status']);

        $banners->save($this->banner, [
            'title' => trim($this->title) ?: null,
            'is_active' => $this->isActive,
            'display_order' => $this->displayOrder,
            'focal_x' => $this->focalX,
            'focal_y' => $this->focalY,
        ], $this->image, $staff);

        session()->flash('status', 'Banner saved.');

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
