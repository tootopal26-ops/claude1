<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The post-login landing page — reachable by any authenticated staff
 * account regardless of permissions (no `permission:` route
 * middleware), unlike every other admin/staff route. Every other
 * landing target requires a specific permission, so a staff account
 * configured with a narrower preset (e.g. legal + notifications only,
 * with no tournaments.view) would otherwise land straight on a bare
 * 403 the moment they log in, with no nav rendered to get anywhere
 * else — see BUG-003 in qa-report/BUG_REPORT.md.
 */
#[Layout('components.layouts.admin')]
class Home extends Component
{
    public function render()
    {
        $user = Auth::guard('staff')->user();

        $sections = collect([
            ['permission' => 'tournaments.view', 'route' => 'admin.tournaments.index', 'label' => 'Tournaments'],
            ['permission' => 'notifications.send', 'route' => 'admin.notifications.index', 'label' => 'Notifications'],
            ['permission' => 'users.view', 'route' => 'admin.users.index', 'label' => 'Players'],
            ['permission' => 'recurring_tournaments.manage', 'route' => 'admin.templates.index', 'label' => 'Templates'],
            ['permission' => 'withdrawals.manage', 'route' => 'admin.withdrawals.index', 'label' => 'Withdrawals'],
            ['permission' => 'payments.view', 'route' => 'admin.deposits.index', 'label' => 'Deposits'],
            ['permission' => 'leaderboards.manage', 'route' => 'admin.leaderboards.index', 'label' => 'Leaderboards'],
            ['permission' => 'salary.view', 'route' => 'admin.staff-salary.settlements.index', 'label' => 'Salary'],
            ['permission' => 'storage.view', 'route' => 'admin.storage.index', 'label' => 'Storage'],
            ['permission' => 'staff.manage', 'route' => 'admin.staff.index', 'label' => 'Staff'],
            ['permission' => 'audit_logs.view', 'route' => 'admin.audit-logs.index', 'label' => 'Audit Log'],
            ['permission' => 'legal.manage', 'route' => 'admin.legal.index', 'label' => 'Legal'],
            ['permission' => 'settings.manage', 'route' => 'admin.settings.index', 'label' => 'Settings'],
        ])->filter(fn (array $section) => $user->hasPermission($section['permission']))->values();

        return view('livewire.admin.home', ['sections' => $sections]);
    }
}
