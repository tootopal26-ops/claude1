<?php

namespace App\Livewire\Admin;

use App\Domain\Leaderboard\LeaderboardBannerService;
use App\Models\LeaderboardBanner;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class LeaderboardBannerList extends Component
{
    use WithPagination;

    public function toggle(int $bannerId, LeaderboardBannerService $banners): void
    {
        $banner = LeaderboardBanner::findOrFail($bannerId);
        $banners->setActive($banner, ! $banner->is_active, $this->actor());

        session()->flash('status', 'The banner is now '.($banner->is_active ? 'visible in the app.' : 'hidden from the app.'));
    }

    public function moveUp(int $bannerId, LeaderboardBannerService $banners): void
    {
        $banners->move(LeaderboardBanner::findOrFail($bannerId), -1, $this->actor());
    }

    public function moveDown(int $bannerId, LeaderboardBannerService $banners): void
    {
        $banners->move(LeaderboardBanner::findOrFail($bannerId), 1, $this->actor());
    }

    public function delete(int $bannerId, LeaderboardBannerService $banners): void
    {
        $banners->delete(LeaderboardBanner::findOrFail($bannerId), $this->actor());

        session()->flash('status', 'The banner was deleted.');
    }

    private function actor()
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission('leaderboards.manage'), 403);

        return $staff;
    }

    public function render()
    {
        $this->actor();

        return view('livewire.admin.leaderboard-banner-list', [
            'banners' => LeaderboardBanner::query()->with('thumbnailAsset')->orderBy('display_order')->orderBy('id')->paginate(10),
        ]);
    }
}
