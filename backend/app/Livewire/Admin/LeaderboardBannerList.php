<?php

namespace App\Livewire\Admin;

use App\Domain\Leaderboard\LeaderboardBannerService;
use App\Models\LeaderboardBanner;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class LeaderboardBannerList extends Component
{
    use WithPagination;

    /** leaderboard | home — fixed by the route the page was opened from. */
    #[Locked]
    public string $placement = 'leaderboard';

    /** The Home banner routes pass `placement: home` as a route default. */
    public function mount(string $placement = 'leaderboard'): void
    {
        abort_unless(in_array($placement, LeaderboardBanner::PLACEMENTS, true), 404);

        $this->placement = $placement;
        $this->actor();
    }

    public function toggle(int $bannerId, LeaderboardBannerService $banners): void
    {
        $banner = $this->find($bannerId);
        $banners->setActive($banner, ! $banner->is_active, $this->actor());

        session()->flash('status', 'The banner is now '.($banner->is_active ? 'visible in the app.' : 'hidden from the app.'));
    }

    public function moveUp(int $bannerId, LeaderboardBannerService $banners): void
    {
        $banners->move($this->find($bannerId), -1, $this->actor());
    }

    public function moveDown(int $bannerId, LeaderboardBannerService $banners): void
    {
        $banners->move($this->find($bannerId), 1, $this->actor());
    }

    public function delete(int $bannerId, LeaderboardBannerService $banners): void
    {
        $banners->delete($this->find($bannerId), $this->actor());

        session()->flash('status', 'The banner was deleted.');
    }

    private function find(int $bannerId): LeaderboardBanner
    {
        return LeaderboardBanner::query()->placement($this->placement)->findOrFail($bannerId);
    }

    private function actor()
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff->hasPermission(self::permissionFor($this->placement)), 403);

        return $staff;
    }

    /** Home banners are home-screen content (with the game cards); leaderboard banners belong to the leaderboard. */
    public static function permissionFor(string $placement): string
    {
        return $placement === 'home' ? 'games.manage' : 'leaderboards.manage';
    }

    public function render()
    {
        $this->actor();

        return view('livewire.admin.leaderboard-banner-list', [
            'banners' => LeaderboardBanner::query()->placement($this->placement)->with('thumbnailAsset')->orderBy('display_order')->orderBy('id')->paginate(10),
        ]);
    }
}
