<?php

namespace App\Livewire\Admin\Ads\Concerns;

use App\Models\StaffUser;
use Illuminate\Support\Facades\Auth;

/**
 * Shared by every Ads & Monetization page. Reading needs `ads.view` (the route enforces
 * it); every change — settings, ad units, placements, revenue figures, settlements,
 * campaigns — needs `ads.manage`, re-checked here on each action so a read-only staff
 * account can never change anything, whatever the page shows.
 */
trait AdsPage
{
    protected function actor(): StaffUser
    {
        /** @var StaffUser $staff */
        $staff = Auth::guard('staff')->user();

        return $staff;
    }

    protected function canManage(): bool
    {
        return $this->actor()->hasPermission('ads.manage');
    }

    protected function ensureCanManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    /**
     * The section's own navigation (rendered at the top of each page).
     *
     * @return list<array{label: string, route: string, permission: string}>
     */
    public static function sections(): array
    {
        return [
            ['label' => 'Overview', 'route' => 'ads.overview', 'permission' => 'ads.view'],
            ['label' => 'Ads Earning', 'route' => 'ads.earning', 'permission' => 'ads.manage'],
            ['label' => 'Ad units', 'route' => 'ads.units', 'permission' => 'ads.manage'],
            ['label' => 'Placements', 'route' => 'ads.placements', 'permission' => 'ads.manage'],
            ['label' => 'Revenue reports', 'route' => 'ads.revenue', 'permission' => 'ads.view'],
            ['label' => 'Weekly settlements', 'route' => 'ads.settlements', 'permission' => 'ads.view'],
            ['label' => 'Daily login rewards', 'route' => 'ads.daily-rewards', 'permission' => 'ads.manage'],
            ['label' => 'Reward campaigns', 'route' => 'ads.campaigns', 'permission' => 'ads.manage'],
            ['label' => 'Tournament tickets', 'route' => 'ads.tickets', 'permission' => 'ads.manage'],
            ['label' => 'User ad activity', 'route' => 'ads.users', 'permission' => 'ads.view'],
            ['label' => 'Verification logs', 'route' => 'ads.verification', 'permission' => 'ads.view'],
            ['label' => 'Audit log', 'route' => 'ads.audit', 'permission' => 'ads.view'],
        ];
    }
}
