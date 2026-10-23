<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\DepositOrder;
use App\Models\RiskAlert;
use App\Models\StaffSalarySettlement;
use App\Models\StaffUser;
use App\Models\Tournament;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\Portal;
use App\View\AdminNavigation;
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
 *
 * Everything shown here is real data, and each figure is only queried
 * and displayed when the account holds the permission for the section it
 * links to. Super-admins get the Admin Console view; everyone else gets
 * the Staff Portal workspace (same data rules, different framing).
 */
#[Layout('components.layouts.admin')]
class Home extends Component
{
    public function render()
    {
        /** @var StaffUser $user */
        $user = Auth::guard('staff')->user();

        $groups = AdminNavigation::forUser($user);
        $sections = collect($groups)
            ->flatMap(fn (array $group) => $group['items'])
            ->reject(fn (array $item) => $item['route'] === Portal::routeName('home'))
            ->values();

        $stats = [];

        if ($user->hasPermission('tournaments.view')) {
            $upcoming = Tournament::where('status', 'upcoming')->count();
            $live = Tournament::where('status', 'ongoing')->count();
            $stats[] = [
                'label' => 'Active tournaments',
                'value' => number_format($upcoming + $live),
                'hint' => "{$upcoming} upcoming · {$live} live",
                'icon' => 'tournaments',
                'tone' => 'brand',
                'href' => portal_route('tournaments.index'),
            ];
        }

        if ($user->hasPermission('users.view')) {
            $stats[] = [
                'label' => 'Registered players',
                'value' => number_format(User::count()),
                'hint' => number_format(User::where('created_at', '>=', now()->subDays(7))->count()).' joined in the last 7 days',
                'icon' => 'users',
                'tone' => 'info',
                'href' => portal_route('users.index'),
            ];
        }

        if ($user->hasPermission('payments.view')) {
            $paid = DepositOrder::where('status', 'success')->where('paid_at', '>=', now()->subDay());
            $stats[] = [
                'label' => 'Deposits (24h)',
                'value' => number_format((clone $paid)->count()),
                'hint' => 'Successful orders · '.number_format((float) (clone $paid)->sum('amount'), 2).' coins',
                'icon' => 'deposit',
                'tone' => 'success',
                'href' => portal_route('deposits.index'),
            ];
        }

        if ($user->hasPermission('withdrawals.manage')) {
            $pending = WithdrawalRequest::where('status', 'pending')->count();
            $inFlight = WithdrawalRequest::whereIn('status', ['approved', 'processing'])->count();
            $stats[] = [
                'label' => 'Pending withdrawals',
                'value' => number_format($pending),
                'hint' => "{$inFlight} approved or processing",
                'icon' => 'withdrawal',
                'tone' => $pending > 0 ? 'warning' : 'neutral',
                'href' => portal_route('withdrawals.index'),
            ];
        }

        if ($user->hasPermission('audit_logs.view')) {
            $open = RiskAlert::whereNull('reviewed_at')->count();
            $stats[] = [
                'label' => 'Open risk alerts',
                'value' => number_format($open),
                'hint' => $open > 0 ? 'Awaiting review' : 'Nothing awaiting review',
                'icon' => 'alert',
                'tone' => $open > 0 ? 'danger' : 'neutral',
                'href' => portal_route('risk-alerts.index'),
            ];
        }

        $attention = collect();

        if ($user->hasPermission('withdrawals.manage')) {
            $count = WithdrawalRequest::where('status', 'pending')->count();
            $count > 0 && $attention->push(['label' => 'Withdrawal requests to review', 'count' => $count, 'href' => portal_route('withdrawals.index')]);
        }

        if ($user->hasPermission('results.submit')) {
            $count = Tournament::where('status', 'ongoing')->count();
            $count > 0 && $attention->push(['label' => 'Live tournaments awaiting results', 'count' => $count, 'href' => portal_route('tournaments.index')]);
        }

        if ($user->hasPermission('salary.view')) {
            $count = StaffSalarySettlement::where('status', 'pending')->count();
            $count > 0 && $attention->push(['label' => 'Salary settlements awaiting payment', 'count' => $count, 'href' => portal_route('staff-salary.settlements.index')]);
        }

        if ($user->hasPermission('audit_logs.view')) {
            $count = RiskAlert::whereNull('reviewed_at')->count();
            $count > 0 && $attention->push(['label' => 'Risk alerts to review', 'count' => $count, 'href' => portal_route('risk-alerts.index')]);
        }

        $upcomingTournaments = $user->hasPermission('tournaments.view')
            ? Tournament::with('game')
                ->withCount(['entries as joined_count' => fn ($query) => $query->where('status', 'confirmed')])
                ->where('status', 'upcoming')
                ->orderBy('scheduled_start_at')
                ->limit(5)
                ->get()
            : collect();

        // Staff with audit access see everything; everyone else sees only the
        // actions they performed themselves.
        $canSeeAllActivity = $user->hasPermission('audit_logs.view');
        $activity = AuditLog::query()
            ->with('actor')
            ->when(! $canSeeAllActivity, fn ($query) => $query
                ->where('actor_type', $user->getMorphClass())
                ->where('actor_id', $user->getKey()))
            ->latest('id')
            ->limit(8)
            ->get();

        return view('livewire.admin.home', [
            'user' => $user,
            'isAdminConsole' => (bool) $user->is_super_admin,
            'sections' => $sections,
            'stats' => $stats,
            'attention' => $attention,
            'upcomingTournaments' => $upcomingTournaments,
            'activity' => $activity,
            'canSeeAllActivity' => $canSeeAllActivity,
        ]);
    }
}
