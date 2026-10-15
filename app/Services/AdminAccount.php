<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Payments\Gateways;
use App\Support\Clock;
use App\Support\Ua;

/** Signed-in admin's sessions, the audit trail, and the "is the shop ready?" status block. */
final class AdminAccount
{
    /** @return list<array<string,mixed>> */
    public static function sessions(array $auth): array
    {
        $rows = Db::all(
            'SELECT id, ip, user_agent, remember, created_at, last_seen_at, expires_at FROM sessions '
            . 'WHERE admin_id = ? AND revoked_at IS NULL AND expires_at > ? AND idle_expires_at > ? ORDER BY last_seen_at DESC LIMIT 30',
            [$auth['admin']['id'], Clock::sql(), Clock::sql()]
        );
        return array_map(static function (array $s) use ($auth): array {
            $ua = (string) $s['user_agent'];
            return [
                'id' => (int) $s['id'], 'current' => (int) $s['id'] === (int) $auth['session']['id'],
                'device' => Ua::browser($ua) . ' on ' . Ua::os($ua), 'kind' => Ua::device($ua), 'ip' => $s['ip'], 'remember' => (bool) $s['remember'],
                'created_at' => Clock::iso($s['created_at']), 'last_seen_at' => Clock::iso($s['last_seen_at']), 'expires_at' => Clock::iso($s['expires_at']),
            ];
        }, $rows);
    }

    public static function revokeSession(int $id, array $auth, Request $req): void
    {
        if ($id === (int) $auth['session']['id']) {
            throw new HttpException(409, 'This is the session you are using — use Sign out instead.', 'current_session');
        }
        $n = Db::exec('UPDATE sessions SET revoked_at = ? WHERE id = ? AND admin_id = ? AND revoked_at IS NULL', [Clock::sql(), $id, $auth['admin']['id']]);
        if ($n === 0) {
            throw new HttpException(404, 'That session no longer exists.');
        }
        Audit::log('session.revoked', 'session', $id, [], $req, $auth['admin']);
    }

    public static function revokeOthers(array $auth, Request $req): int
    {
        $n = AdminAuth::revokeOthers((int) $auth['admin']['id'], (int) $auth['session']['id']);
        Audit::log('session.revoked', 'session', 'all-others', ['count' => $n], $req, $auth['admin']);
        return $n;
    }

    /**
     * @param array<string,mixed> $q group (all|security|orders|catalog|settings|login), q, page
     * @return array<string,mixed>
     */
    public static function audit(array $q): array
    {
        $groups = [
            'security' => ["action LIKE 'admin.%'", "action LIKE 'session.%'"],
            'login'    => ["action IN ('admin.login','admin.login_failed','admin.logout')"],
            'orders'   => ["action LIKE 'order.%'"],
            'catalog'  => ["action LIKE 'service.%'", "action LIKE 'platform.%'"],
            'settings' => ["action LIKE 'settings.%'", "action LIKE 'smm.%'", "action LIKE 'gateway.%'", "action LIKE 'upload.%'"],
        ];
        $where = ['1 = 1'];
        $params = [];
        $group = (string) ($q['group'] ?? 'all');
        if (isset($groups[$group])) {
            $where[] = '(' . implode(' OR ', $groups[$group]) . ')';
        }
        $search = trim((string) ($q['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . addcslashes(mb_substr($search, 0, 80), '%_\\') . '%';
            $where[] = '(action LIKE ? OR admin_label LIKE ? OR entity_id LIKE ? OR meta_json LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $per = 30;
        $page = max(1, (int) ($q['page'] ?? 1));
        $whereSql = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE {$whereSql}", $params);
        $rows = Db::all("SELECT * FROM audit_logs WHERE {$whereSql} ORDER BY id DESC LIMIT ? OFFSET ?", array_merge($params, [$per, ($page - 1) * $per]));
        return [
            'rows' => array_map(static function (array $r): array {
                $meta = $r['meta_json'] !== null ? json_decode((string) $r['meta_json'], true) : null;
                return [
                    'id' => (int) $r['id'], 'action' => $r['action'], 'label' => Audit::label((string) $r['action']), 'admin' => $r['admin_label'],
                    'entity' => $r['entity_type'], 'entity_id' => $r['entity_id'], 'ip' => $r['ip'], 'meta' => is_array($meta) ? $meta : null,
                    'device' => $r['user_agent'] ? Ua::browser((string) $r['user_agent']) . ' / ' . Ua::os((string) $r['user_agent']) : null, 'at' => Clock::iso($r['created_at']),
                ];
            }, $rows),
            'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'total' => $total,
        ];
    }

    /**
     * Setup checklist + live health shown on the dashboard and in the sidebar.
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $gw = Gateways::activeRow();
        $gwOk = $gw !== null;
        $gwView = $gw !== null ? Gateways::adminView($gw) : null;
        $provider = SmmProviders::defaultRow();
        $autoServices = (int) Db::val("SELECT COUNT(*) FROM services WHERE mode = 'AUTO' AND deleted_at IS NULL AND status = 'active'");
        $cronLast = (int) Settings::get('cron.last_run', 0);
        $cronAge = $cronLast > 0 ? Clock::now() - $cronLast : null;
        $cronOk = $cronAge !== null && $cronAge <= 900;
        $mustChange = (bool) Db::val('SELECT must_change_password FROM admins ORDER BY id LIMIT 1');

        $items = [
            ['key' => 'gateway', 'ok' => $gwOk, 'label' => 'Payment gateway', 'detail' => $gwOk ? ($gwView['environment'] === 'production' ? 'Active (live)' : 'Active (test mode)') : 'Not configured — customers cannot pay yet', 'href' => url('/admin/gateway'), 'level' => $gwOk ? ($gwView['environment'] === 'production' ? 'ok' : 'warn') : 'error'],
            ['key' => 'smm', 'ok' => $provider !== null, 'label' => 'SMM provider', 'detail' => $provider !== null ? 'Connected: ' . $provider['name'] : ($autoServices > 0 ? 'Required for AUTO services' : 'Optional — add one to automate delivery'), 'href' => url('/admin/smm'), 'level' => $provider !== null ? 'ok' : 'info'],
            ['key' => 'cron', 'ok' => $cronOk, 'label' => 'Background tasks (cron)', 'detail' => $cronAge === null ? 'Has never run — add the cron job (see Deploy guide)' : ($cronOk ? 'Last run ' . self::ago($cronAge) : 'Last run ' . self::ago($cronAge) . ' — check your cron job'), 'href' => url('/admin/gateway'), 'level' => $cronOk ? 'ok' : 'warn'],
            ['key' => 'password', 'ok' => !$mustChange, 'label' => 'Admin password', 'detail' => $mustChange ? 'Change the temporary password now' : 'Secure', 'href' => url('/admin/security'), 'level' => $mustChange ? 'error' : 'ok'],
        ];
        try {
            $flags = Risk::openCounts()['attention'];
        } catch (\Throwable) {
            $flags = 0;        // the table arrives with a database update that may not be applied yet: the banner below says so
        }
        return [
            'approvable' => Fulfilment::approvableCount(),
            'payment_flags' => $flags,
            'setup' => $items,
            'auto_services' => $autoServices,
            'smm_ok' => $provider !== null,
            'must_change_password' => $mustChange,
            'pending_migrations' => count(\App\Support\Migrator::pending()),
            'ready' => $gwOk,
            'cron' => ['last_run_at' => $cronLast > 0 ? gmdate('c', $cronLast) : null, 'ok' => $cronOk],
            'maintenance' => (bool) Settings::get('site.maintenance', false),
            'gateway_env' => $gwView['environment'] ?? null,
        ];
    }

    private static function ago(int $s): string
    {
        return match (true) {
            $s < 90 => 'just now',
            $s < 3600 => (int) round($s / 60) . ' min ago',
            $s < 86400 => (int) round($s / 3600) . ' h ago',
            default => (int) round($s / 86400) . ' days ago',
        };
    }
}
