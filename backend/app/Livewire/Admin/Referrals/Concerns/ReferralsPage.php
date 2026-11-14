<?php

namespace App\Livewire\Admin\Referrals\Concerns;

use App\Models\StaffUser;
use Illuminate\Support\Facades\Auth;

/**
 * Shared by every Referral Management page. One permission (`referrals.manage`) covers
 * both reading and changing anything here — unlike Ads & Monetization's split view/manage,
 * this is a small enough area that a single permission is clearer than two.
 */
trait ReferralsPage
{
    protected function actor(): StaffUser
    {
        /** @var StaffUser $staff */
        $staff = Auth::guard('staff')->user();

        return $staff;
    }

    protected function ensureCanManage(): void
    {
        abort_unless($this->actor()->hasPermission('referrals.manage'), 403);
    }

    /**
     * @return list<array{label: string, route: string}>
     */
    public static function sections(): array
    {
        return [
            ['label' => 'Settings', 'route' => 'referrals.index'],
            ['label' => 'History', 'route' => 'referrals.history'],
        ];
    }
}
