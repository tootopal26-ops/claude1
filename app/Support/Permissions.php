<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Role-based authorisation for the admin panel.
 *
 * Until now every admin was an `owner` and "signed in" was the only check. Sensitive actions (manual payment confirmation, financial
 * adjustments, price changes, bulk order actions, AI settings…) now name the permission they need, and the server — never the browser —
 * enforces it (BaseController::authorize). `owner` can do everything, so existing installations behave exactly as before; other roles are
 * assigned with `php bin/admin.php role <username> <role>`.
 *
 *   owner    everything
 *   manager  runs the shop: orders, recovery, finance entries, ads, alerts, reports, the support inbox, pricing proposals and the pause
 *            switches — but not payment overrides, price activation, admin accounts, AI provider settings or credentials
 *   support  looks things up and helps customers: read-only on orders, payments and recovery; the support assistant's inbox
 *   analyst  read-only dashboards and reports
 */
final class Permissions
{
    public const ROLES = ['owner', 'manager', 'support', 'analyst'];

    /** permission => what it allows (shown in the docs and in the "no permission" message) */
    public const ALL = [
        'dashboard.view'     => 'See the dashboard',
        'orders.view'        => 'See orders and the order queue',
        'orders.act'         => 'Approve, complete, cancel and refund single orders',
        'orders.bulk'        => 'Bulk actions such as Approve All',
        'orders.recover'     => 'Retry, reconcile and escalate orders in the recovery queue',
        'payments.view'      => 'See payments, webhooks and payment flags',
        'payments.review'    => 'Re-check a payment with the gateway and review or dismiss a payment flag',
        'payments.override'  => 'Confirm a payment by hand (an audited manual decision, never a gateway confirmation)',
        'finance.view'       => 'See profit, costs and reconciliation',
        'finance.adjust'     => 'Record costs, expenses, chargebacks and ad spend',
        'ads.view'           => 'See Ads Intelligence',
        'ads.manage'         => 'Edit advertising settings and campaign spend',
        'alerts.view'        => 'See Business Watchdog alerts',
        'alerts.manage'      => 'Acknowledge and resolve alerts, change thresholds',
        'reports.view'       => 'See daily reports',
        'reports.generate'   => 'Regenerate a daily report',
        'support.view'       => 'See support conversations and assistant statistics',
        'support.configure'  => 'Configure the support assistant',
        'ai.configure'       => 'Configure the AI provider',
        'pricing.view'       => 'See adaptive pricing, the simulator, the profit guard and the learning history',
        'pricing.propose'    => 'Create pricing experiments and run the simulator',
        'pricing.approve'    => 'Approve and start an experiment, or switch it from dry-run to live',
        'pricing.pause'      => 'Pause or resume pricing (globally or per service) and roll an experiment back',
        'catalog.manage'     => 'Edit services, platforms, tags and website settings',
        'integrations.manage' => 'Edit the payment gateway and SMM API credentials',
        'jobs.view'          => 'See scheduled-job health',
        'jobs.run'           => 'Run a scheduled job now',
        'admins.manage'      => 'Manage admin accounts and roles',
    ];

    private const MATRIX = [
        'manager' => [
            'dashboard.view', 'orders.view', 'orders.act', 'orders.bulk', 'orders.recover', 'payments.view', 'payments.review',
            'finance.view', 'finance.adjust', 'ads.view', 'ads.manage', 'alerts.view', 'alerts.manage', 'reports.view', 'reports.generate',
            'support.view', 'support.configure', 'pricing.view', 'pricing.propose', 'pricing.pause', 'catalog.manage', 'jobs.view', 'jobs.run',
        ],
        'support' => [
            'dashboard.view', 'orders.view', 'payments.view', 'alerts.view', 'support.view', 'jobs.view',
        ],
        'analyst' => [
            'dashboard.view', 'orders.view', 'payments.view', 'finance.view', 'ads.view', 'alerts.view', 'reports.view', 'pricing.view', 'jobs.view',
        ],
    ];

    public static function isRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /** An unknown role has no permissions at all (never "everything"). */
    public static function can(string $role, string $permission): bool
    {
        if (!isset(self::ALL[$permission])) {
            return false;
        }
        if ($role === 'owner') {
            return true;
        }
        return in_array($permission, self::MATRIX[$role] ?? [], true);
    }

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        return $role === 'owner' ? array_keys(self::ALL) : (self::MATRIX[$role] ?? []);
    }
}
