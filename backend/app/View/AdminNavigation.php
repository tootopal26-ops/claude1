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
                    ['label' => 'Recurring Templates', 'route' => 'admin.templates.index', 'icon' => 'calendar', 'permission' => 'recurring_tournaments.manage', 'active' => ['admin.templates.*'], 'description' => 'Generate tournaments automatically on a schedule.'],
                ],
            ],
            [
                'label' => 'Users & Finance',
                'items' => [
                    ['label' => 'Players', 'route' => 'admin.users.index', 'icon' => 'users', 'permission' => 'users.view', 'active' => ['admin.users.*'], 'description' => 'Look up players, wallets and account status.'],
                    ['label' => 'Deposits', 'route' => 'admin.deposits.index', 'icon' => 'deposit', 'permission' => 'payments.view', 'active' => ['admin.deposits.*'], 'description' => 'Review incoming payment orders.'],
                    ['label' => 'Redeem Codes', 'route' => 'admin.redeem-codes.index', 'icon' => 'ticket', 'permission' => 'redeem_codes.manage', 'active' => ['admin.redeem-codes.*'], 'description' => 'Reward codes players can redeem for coins.'],
                    ['label' => 'Withdrawals', 'route' => 'admin.withdrawals.index', 'icon' => 'withdrawal', 'permission' => 'withdrawals.manage', 'active' => ['admin.withdrawals.*'], 'description' => 'Approve, process and settle payout requests.'],
                    ['label' => 'Leaderboard Management', 'route' => 'admin.leaderboards.index', 'icon' => 'leaderboard', 'permission' => 'leaderboards.manage', 'active' => ['admin.leaderboards.*'], 'description' => 'Rankings, prize rules, payout settlement and banners.'],
                    ['label' => 'Payment Settings', 'route' => 'admin.payment-settings', 'icon' => 'wallet', 'permission' => 'payments.manage', 'active' => ['admin.payment-settings'], 'description' => 'Payment gateway configuration.'],
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
