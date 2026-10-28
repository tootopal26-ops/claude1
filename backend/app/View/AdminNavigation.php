<?php

namespace App\View;

use App\Models\StaffUser;
use App\Support\Portal;

/**
 * The one catalog of Admin/Staff navigation. The sidebar, the dashboard's
 * section cards and the header's "where am I" context all read from here, so
 * a new route needs adding in exactly one place.
 *
 * The catalog is written with the Admin portal's route names (admin.*);
 * forUser() re-targets every route and active-pattern to the account's own
 * portal (admin.* or staff.*). Filtering by permission here only decides what
 * is *shown*. Every route still carries its own `portal:` and `permission:`
 * middleware, which remain the authority.
 */
class AdminNavigation
{
    /**
     * @return list<array{label: string, items: list<array{label: string, route: string, icon: string, permission: ?string, active: list<string>, description: string}>}>
     */
    public static function catalog(): array
    {
        return [
            [
                'label' => 'Overview',
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'admin.home', 'icon' => 'dashboard', 'permission' => null, 'active' => ['admin.home'], 'description' => 'Your workspace at a glance.'],
                ],
            ],
            [
                'label' => 'Tournament Operations',
                'items' => [
                    ['label' => 'Tournaments', 'route' => 'admin.tournaments.index', 'icon' => 'tournaments', 'permission' => 'tournaments.view', 'active' => ['admin.tournaments.*'], 'description' => 'Schedule tournaments, manage entries and publish results.'],
                    ['label' => 'Tournament Games', 'route' => 'admin.tournament-games.index', 'icon' => 'image', 'permission' => 'games.manage', 'active' => ['admin.tournament-games.*'], 'description' => 'The game cards on the app home screen.'],
                    ['label' => 'Home Banners', 'route' => 'admin.home-banners.index', 'icon' => 'image', 'permission' => 'games.manage', 'active' => ['admin.home-banners.*'], 'description' => 'The 16:9 hero carousel at the top of the app home screen.'],
                    ['label' => 'Recurring Templates', 'route' => 'admin.templates.index', 'icon' => 'calendar', 'permission' => 'recurring_tournaments.manage', 'active' => ['admin.templates.*'], 'description' => 'Generate tournaments automatically on a schedule.'],
                ],
            ],
            [
                'label' => 'Users & Finance',
                'items' => [
                    ['label' => 'Players', 'route' => 'admin.users.index', 'icon' => 'users', 'permission' => 'users.view', 'active' => ['admin.users.*'], 'description' => 'Look up players, wallets and account status.'],
                    ['label' => 'Deposits', 'route' => 'admin.deposits.index', 'icon' => 'deposit', 'permission' => 'payments.view', 'active' => ['admin.deposits.*'], 'description' => 'Review incoming payment orders.'],
                    ['label' => 'Redeem Codes', 'route' => 'admin.redeem-codes.index', 'icon' => 'ticket', 'permission' => 'redeem_codes.manage', 'active' => ['admin.redeem-codes.*'], 'description' => 'Reward codes players can redeem for coins.'],
                    ['label' => 'Section Unlock Pricing', 'route' => 'admin.section-unlock-pricing.index', 'icon' => 'unlock', 'permission' => 'section_unlocks.manage', 'active' => ['admin.section-unlock-pricing.*'], 'description' => 'Coin prices for locked app sections.'],
                    ['label' => 'Withdrawals', 'route' => 'admin.withdrawals.index', 'icon' => 'withdrawal', 'permission' => 'withdrawals.manage', 'active' => ['admin.withdrawals.*'], 'description' => 'Approve, process and settle payout requests.'],
                    ['label' => 'Leaderboard Management', 'route' => 'admin.leaderboards.index', 'icon' => 'leaderboard', 'permission' => 'leaderboards.manage', 'active' => ['admin.leaderboards.*'], 'description' => 'Rankings, prize rules, payout settlement and banners.'],
                    ['label' => 'Payment Settings', 'route' => 'admin.payment-settings', 'icon' => 'wallet', 'permission' => 'payments.manage', 'active' => ['admin.payment-settings'], 'description' => 'Payment gateway configuration.'],
                ],
            ],
            [
                'label' => 'Ads & Monetization',
                'items' => [
                    ['label' => 'Ads Overview', 'route' => 'admin.ads.overview', 'icon' => 'dashboard', 'permission' => 'ads.view', 'active' => ['admin.ads.overview'], 'description' => 'Ad requests, impressions, verified rewards and revenue.'],
                    ['label' => 'Ads Earning', 'route' => 'admin.ads.earning', 'icon' => 'wallet', 'permission' => 'ads.manage', 'active' => ['admin.ads.earning'], 'description' => 'Rewarded Ads switch, revenue share and Ads Earning rules.'],
                    ['label' => 'Ad Units', 'route' => 'admin.ads.units', 'icon' => 'presets', 'permission' => 'ads.manage', 'active' => ['admin.ads.units'], 'description' => 'LevelPlay ad unit IDs for each format.'],
                    ['label' => 'Ad Placements', 'route' => 'admin.ads.placements', 'icon' => 'image', 'permission' => 'ads.manage', 'active' => ['admin.ads.placements'], 'description' => 'Where ads may appear, caps and feed intervals.'],
                    ['label' => 'Revenue Reports', 'route' => 'admin.ads.revenue', 'icon' => 'leaderboard', 'permission' => 'ads.view', 'active' => ['admin.ads.revenue'], 'description' => 'Provider-reported revenue and app estimates.'],
                    ['label' => 'Weekly Settlements', 'route' => 'admin.ads.settlements', 'icon' => 'salary', 'permission' => 'ads.view', 'active' => ['admin.ads.settlements'], 'description' => 'Ads Earning revenue-share payouts.'],
                    ['label' => 'Daily Login Rewards', 'route' => 'admin.ads.daily-rewards', 'icon' => 'calendar', 'permission' => 'ads.manage', 'active' => ['admin.ads.daily-rewards'], 'description' => 'The daily reward popup and its range.'],
                    ['label' => 'Reward Campaigns', 'route' => 'admin.ads.campaigns', 'icon' => 'ticket', 'permission' => 'ads.manage', 'active' => ['admin.ads.campaigns'], 'description' => 'Fixed-reward rewarded-ad campaigns.'],
                    ['label' => 'Tournament Tickets', 'route' => 'admin.ads.tickets', 'icon' => 'tournaments', 'permission' => 'ads.manage', 'active' => ['admin.ads.tickets'], 'description' => 'Rewarded-ad tickets for free tournaments.'],
                    ['label' => 'User Ad Activity', 'route' => 'admin.ads.users', 'icon' => 'users', 'permission' => 'ads.view', 'active' => ['admin.ads.users'], 'description' => 'One player\'s ads, rewards and eligibility.'],
                    ['label' => 'Verification Logs', 'route' => 'admin.ads.verification', 'icon' => 'shield', 'permission' => 'ads.view', 'active' => ['admin.ads.verification'], 'description' => 'Every ad-network reward callback and its outcome.'],
                    ['label' => 'Ads Audit Log', 'route' => 'admin.ads.audit', 'icon' => 'audit', 'permission' => 'ads.view', 'active' => ['admin.ads.audit'], 'description' => 'Changes to ad settings, units, revenue and settlements.'],
                ],
            ],
            [
                'label' => 'Team Management',
                'items' => [
                    ['label' => 'Staff', 'route' => 'admin.staff.index', 'icon' => 'user', 'permission' => 'staff.manage', 'active' => ['admin.staff.*'], 'description' => 'Staff accounts and their access.'],
                    ['label' => 'Permission Presets', 'route' => 'admin.presets.index', 'icon' => 'presets', 'permission' => 'staff.manage', 'active' => ['admin.presets.*'], 'description' => 'Reusable sets of permissions.'],
                    ['label' => 'Salary Settlements', 'route' => 'admin.staff-salary.settlements.index', 'icon' => 'salary', 'permission' => 'salary.view', 'active' => ['admin.staff-salary.settlements.*'], 'description' => 'Weekly staff payouts.'],
                    ['label' => 'Salary Rules', 'route' => 'admin.staff-salary.rules.index', 'icon' => 'presets', 'permission' => 'salary.manage', 'active' => ['admin.staff-salary.rules.*'], 'description' => 'Per-action pay rates.'],
                ],
            ],
            [
                'label' => 'Communication',
                'items' => [
                    ['label' => 'Notifications', 'route' => 'admin.notifications.index', 'icon' => 'notifications', 'permission' => 'notifications.send', 'active' => ['admin.notifications.*'], 'description' => 'Send and review player notifications.'],
                    ['label' => 'Customer Support', 'route' => 'admin.customer-support', 'icon' => 'info', 'permission' => 'settings.manage', 'active' => ['admin.customer-support'], 'description' => 'The support link the app opens from Profile.'],
                ],
            ],
            [
                'label' => 'System',
                'items' => [
                    ['label' => 'Storage', 'route' => 'admin.storage.index', 'icon' => 'storage', 'permission' => 'storage.view', 'active' => ['admin.storage.*'], 'description' => 'File storage usage and retention.'],
                    ['label' => 'Audit Log', 'route' => 'admin.audit-logs.index', 'icon' => 'audit', 'permission' => 'audit_logs.view', 'active' => ['admin.audit-logs.*'], 'description' => 'A record of every administrative action.'],
                    ['label' => 'Risk Alerts', 'route' => 'admin.risk-alerts.index', 'icon' => 'alert', 'permission' => 'audit_logs.view', 'active' => ['admin.risk-alerts.*'], 'description' => 'Flagged activity awaiting review.'],
                    ['label' => 'Legal', 'route' => 'admin.legal.index', 'icon' => 'shield', 'permission' => 'legal.manage', 'active' => ['admin.legal.*'], 'description' => 'Terms and policy documents.'],
                    ['label' => 'Settings', 'route' => 'admin.settings.index', 'icon' => 'settings', 'permission' => 'settings.manage', 'active' => ['admin.settings.*'], 'description' => 'Platform-wide configuration.'],
                ],
            ],
        ];
    }

    /**
     * The catalog reduced to what this account may open; groups left empty
     * are dropped.
     *
     * @return list<array{label: string, items: list<array{label: string, route: string, icon: string, permission: ?string, active: list<string>, description: string}>}>
     */
    public static function forUser(StaffUser $user): array
    {
        $portal = Portal::for($user);
        $groups = [];

        foreach (self::catalog() as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                if ($item['permission'] !== null && ! $user->hasPermission($item['permission'])) {
                    continue;
                }

                $item['route'] = self::forPortal($item['route'], $portal);
                $item['active'] = array_map(fn (string $pattern) => self::forPortal($pattern, $portal), $item['active']);
                $items[] = $item;
            }

            if ($items !== []) {
                $groups[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        return $groups;
    }

    private static function forPortal(string $routeName, string $portal): string
    {
        return $portal.substr($routeName, strlen(Portal::ADMIN));
    }

    /**
     * The group and item whose route patterns match the current request, for
     * the header's context line.
     *
     * @param  list<array{label: string, items: list<array{label: string, route: string, icon: string, permission: ?string, active: list<string>, description: string}>}>  $groups
     * @return array{group: string, item: string}|null
     */
    public static function current(array $groups): ?array
    {
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if (request()->routeIs(...$item['active'])) {
                    return ['group' => $group['label'], 'item' => $item['label']];
                }
            }
        }

        return null;
    }

    /**
     * Super-admins work in the Admin Console; every other account works in
     * the Staff Portal. Same routes, same guard — only identity and the
     * dashboard differ.
     */
    public static function portalLabel(StaffUser $user): string
    {
        return $user->is_super_admin ? 'Admin Console' : 'Staff Portal';
    }

    public static function roleLabel(StaffUser $user): string
    {
        if ($user->is_super_admin) {
            return 'Super admin';
        }

        return $user->permissionPreset?->name ?? 'No permission preset';
    }
}
