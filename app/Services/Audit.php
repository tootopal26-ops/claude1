<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;
use App\Core\Request;
use App\Support\Clock;

/**
 * Append-only audit trail of important admin actions. Metadata must never
 * contain secrets — callers pass field NAMES that changed, not values of keys.
 */
final class Audit
{
    /**
     * @param array<string,mixed> $meta
     * @param array{id:int,username:string,email:string}|null $admin
     */
    public static function log(string $action, ?string $entityType = null, string|int|null $entityId = null, array $meta = [], ?Request $req = null, ?array $admin = null): void
    {
        $admin ??= $req?->attributes['admin'] ?? null;
        try {
            Db::insert('audit_logs', [
                'admin_id'    => $admin['id'] ?? null,
                'admin_label' => $admin ? substr((string) ($admin['username'] ?? $admin['email'] ?? ''), 0, 120) : 'system',
                'action'      => substr($action, 0, 64),
                'entity_type' => $entityType !== null ? substr($entityType, 0, 40) : null,
                'entity_id'   => $entityId !== null ? substr((string) $entityId, 0, 40) : null,
                'ip'          => $req?->ip(),
                'user_agent'  => $req ? substr($req->userAgent(), 0, 255) : null,
                'meta_json'   => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'created_at'  => Clock::sql(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the action being audited — but it must be visible in the log.
            Log::exception($e, 'Audit write failed', ['action' => $action]);
        }
    }

    /** Human label for an audit action code. */
    public static function label(string $action): string
    {
        return match ($action) {
            'admin.login'              => 'Admin signed in',
            'admin.login_failed'       => 'Failed admin sign-in',
            'admin.logout'             => 'Admin signed out',
            'admin.password_changed'   => 'Password changed',
            'service.created'          => 'Service created',
            'service.updated'          => 'Service updated',
            'service.deleted'          => 'Service deleted',
            'service.price_changed'    => 'Service price changed',
            'platform.updated'         => 'Platform updated',
            'smm.provider_saved'       => 'SMM API configuration changed',
            'smm.provider_tested'      => 'SMM API connection tested',
            'gateway.saved'            => 'Payment gateway changed',
            'gateway.tested'           => 'Payment gateway tested',
            'order.approved'           => 'Order approved',
            'order.approve_all'        => 'Approve All executed',
            'order.action'             => 'Order action',
            'order.refunded'           => 'Order refunded',
            'settings.website'         => 'Website settings changed',
            'settings.advertisement'   => 'Advertisement settings changed',
            'settings.branding'        => 'Branding changed',
            'upload.image'             => 'Image uploaded',
            'session.revoked'          => 'Session revoked',
            default                    => ucwords(str_replace(['.', '_'], ' ', $action)),
        };
    }
}
