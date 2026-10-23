<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\PermissionPreset;
use Illuminate\Database\Seeder;

/**
 * Seeds the full permission catalog named across the project spec's staff
 * permission presets section. Several of these guard features that aren't
 * built yet (wallet, withdrawals, support, storage, salary, recurring
 * tournaments) — seeding the key now costs nothing and lets an Admin
 * pre-configure presets ahead of those phases landing; no route currently
 * checks them, so there is no functional gap this creates today.
 */
class PermissionSeeder extends Seeder
{
    /**
     * @var array<int, array{key: string, label: string, group: string}>
     */
    private const array CATALOG = [
        ['key' => 'tournaments.view', 'label' => 'View tournaments and entries', 'group' => 'Tournaments'],
        ['key' => 'tournaments.manage', 'label' => 'Create, edit, copy tournaments', 'group' => 'Tournaments'],
        ['key' => 'tournaments.cancel', 'label' => 'Manually cancel tournaments', 'group' => 'Tournaments'],
        ['key' => 'tournaments.archive', 'label' => 'Archive tournaments', 'group' => 'Tournaments'],
        ['key' => 'recurring_tournaments.manage', 'label' => 'Manage recurring tournament templates', 'group' => 'Tournaments'],
        ['key' => 'credentials.release', 'label' => 'Release room ID/password to joined players', 'group' => 'Tournament Operations'],
        ['key' => 'results.upload_attachments', 'label' => 'Upload result evidence/attachments', 'group' => 'Tournament Operations'],
        ['key' => 'results.submit', 'label' => 'Submit and revise tournament results', 'group' => 'Tournament Operations'],
        ['key' => 'wallet.manage', 'label' => 'Adjust eligible wallet balances', 'group' => 'Wallet & Payments'],
        ['key' => 'withdrawals.manage', 'label' => 'Approve or reject withdrawal requests', 'group' => 'Wallet & Payments'],
        ['key' => 'payments.view', 'label' => 'View deposit orders and payment provider status', 'group' => 'Wallet & Payments'],
        ['key' => 'payments.manage', 'label' => 'Configure payment provider settings', 'group' => 'Wallet & Payments'],
        ['key' => 'leaderboards.manage', 'label' => 'View leaderboards and force recompute/finalize', 'group' => 'Reports & Audit'],
        ['key' => 'users.view', 'label' => 'View player account details', 'group' => 'Users'],
        ['key' => 'users.ban', 'label' => 'Ban or unban player accounts', 'group' => 'Users'],
        ['key' => 'passwords.reset', 'label' => 'Reset a player password via secure recovery', 'group' => 'Users'],
        ['key' => 'support.manage', 'label' => 'Manage customer support tickets', 'group' => 'Support'],
        ['key' => 'notifications.send', 'label' => 'Send notifications/announcements', 'group' => 'Notifications'],
        ['key' => 'storage.view', 'label' => 'View storage usage', 'group' => 'Operations'],
        ['key' => 'storage.manage', 'label' => 'Manage storage assets (retention, manual deletion, staff documents)', 'group' => 'Operations'],
        ['key' => 'settings.manage', 'label' => 'Configure platform settings (age floor, jurisdiction, risk threshold)', 'group' => 'Operations'],
        ['key' => 'legal.manage', 'label' => 'Publish Terms & Conditions / Privacy Policy versions', 'group' => 'Legal'],
        ['key' => 'salary.view', 'label' => 'View staff salary ledgers', 'group' => 'Staff Salary'],
        ['key' => 'salary.approve', 'label' => 'Approve staff salary payments', 'group' => 'Staff Salary'],
        ['key' => 'salary.manage', 'label' => 'Configure staff salary rules', 'group' => 'Staff Salary'],
        ['key' => 'reports.view', 'label' => 'View reports', 'group' => 'Reports & Audit'],
        ['key' => 'audit_logs.view', 'label' => 'View audit logs and suspicious activity', 'group' => 'Reports & Audit'],
        ['key' => 'games.manage', 'label' => 'Manage tournament game cards (home-screen images and order)', 'group' => 'Tournaments'],
        ['key' => 'redeem_codes.manage', 'label' => 'Create and manage redeem-code campaigns', 'group' => 'Wallet & Payments'],
        ['key' => 'staff.manage', 'label' => 'Manage staff accounts and permission presets', 'group' => 'Staff'],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $permission) {
            Permission::firstOrCreate(['key' => $permission['key']], $permission);
        }

        $this->preset('Tournament Manager', 'Create and operate tournaments end to end.', [
            'tournaments.view', 'tournaments.manage', 'tournaments.cancel', 'tournaments.archive',
            'recurring_tournaments.manage', 'credentials.release', 'results.upload_attachments', 'results.submit',
        ]);

        $this->preset('Support Staff', 'Handle player support and wallet/withdrawal queries.', [
            'users.view', 'support.manage', 'wallet.manage', 'withdrawals.manage', 'passwords.reset',
        ]);

        $this->preset('Read-Only Reports', 'View-only access to reports and audit history.', [
            'reports.view', 'audit_logs.view', 'storage.view', 'salary.view',
        ]);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function preset(string $name, string $description, array $permissionKeys): void
    {
        $preset = PermissionPreset::firstOrCreate(['name' => $name], ['description' => $description]);

        $permissionIds = Permission::whereIn('key', $permissionKeys)->pluck('id');
        $preset->permissions()->syncWithoutDetaching($permissionIds);
    }
}
