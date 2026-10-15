<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Tz;

/**
 * Business alerts: what the Business Watchdog (and a few other checks) found, in one place, without noise.
 *
 * A problem that is still happening updates ONE alert — occurrences and "last detected" go up — instead of creating a new row each run:
 * `active_key` holds the dedupe key while the alert is open or acknowledged and is NULL once it is resolved, and a unique index on it makes
 * "one live alert per problem" a database guarantee, not a hope. A recurrence after resolution opens a fresh alert. Acknowledging only
 * records that a person has seen it (the alert stays live and keeps counting); resolving closes it; a check that sees the problem gone
 * resolves it automatically (resolution = auto).
 *
 * Descriptions are built from numbers and ids only and are cleaned (no paths, no long opaque strings). E-mail, when enabled, goes through the
 * hosting provider's own mail() — nothing secret is ever put in it.
 */
final class Alerts
{
    public const SEVERITIES = ['info', 'warning', 'critical'];

    /** Test seam: fn(string $to, string $subject, string $body, string $headers): bool. Null = PHP's mail(). */
    public static $mailer = null;

    /**
     * Raise (or re-raise) an alert.
     *
     * @param array{entity_type?:?string,entity_id?:string|int|null,dedupe_key?:string,details?:array<string,mixed>} $opts
     * @return array{id:int,created:bool,occurrences:int,notified:bool}
     */
    public static function raise(string $type, string $severity, string $title, string $description, array $opts = []): array
    {
        $severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'warning';
        $entityType = isset($opts['entity_type']) ? mb_substr((string) $opts['entity_type'], 0, 24) : null;
        $entityId = isset($opts['entity_id']) ? mb_substr((string) $opts['entity_id'], 0, 40) : null;
        $key = mb_substr((string) ($opts['dedupe_key'] ?? ($type . ($entityId !== null ? ':' . $entityType . ':' . $entityId : ''))), 0, 120);
        $now = Clock::sql();
        $details = $opts['details'] ?? [];

        $n = Db::exec(
            'INSERT INTO business_alerts (alert_type, severity, title, description, entity_type, entity_id, dedupe_key, active_key, status, occurrences, first_detected_at, last_detected_at, details_json) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', 1, ?, ?, ?) "
            . 'ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_detected_at = VALUES(last_detected_at), title = VALUES(title), description = VALUES(description), '
            . "details_json = VALUES(details_json), severity = IF(FIELD(VALUES(severity), 'info', 'warning', 'critical') > FIELD(severity, 'info', 'warning', 'critical'), VALUES(severity), severity)",
            [
                mb_substr($type, 0, 40), $severity, mb_substr(Jobs::clean($title), 0, 160), mb_substr(Jobs::clean($description), 0, 1000), $entityType, $entityId, $key, $key,
                $now, $now, $details ? mb_substr((string) json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 4000) : null,
            ]
        );
        $row = Db::one('SELECT id, severity, occurrences FROM business_alerts WHERE active_key = ?', [$key]);
        if ($row === null) {
            return ['id' => 0, 'created' => false, 'occurrences' => 0, 'notified' => false];
        }
        return [
            'id' => (int) $row['id'], 'created' => $n === 1, 'occurrences' => (int) $row['occurrences'],
            'notified' => self::maybeNotify((int) $row['id'], (string) $row['severity']),
        ];
    }

    /** The problem is gone: resolve its live alert (if any). @return bool true when an alert was resolved */
    public static function clear(string $dedupeKey): bool
    {
        return Db::exec(
            "UPDATE business_alerts SET status = 'resolved', resolved_at = ?, resolution = 'auto', active_key = NULL WHERE active_key = ?",
            [Clock::sql(), mb_substr($dedupeKey, 0, 120)]
        ) > 0;
    }

    /** @param array{id:int,username:string,email:string} $admin */
    public static function acknowledge(int $id, array $admin, ?Request $req = null): void
    {
        $n = Db::exec(
            "UPDATE business_alerts SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = ? WHERE id = ? AND status = 'open'",
            [$admin['id'], Clock::sql(), $id]
        );
        if ($n === 0) {
            self::mustBeActive($id);
        }
        Audit::log('alert.acknowledged', 'business_alert', $id, [], $req, $admin);
    }

    /** @param array{id:int,username:string,email:string} $admin */
    public static function resolve(int $id, array $admin, ?Request $req = null): void
    {
        $n = Db::exec(
            "UPDATE business_alerts SET status = 'resolved', resolved_at = ?, resolved_by = ?, resolution = 'admin', active_key = NULL WHERE id = ? AND status IN ('open', 'acknowledged')",
            [Clock::sql(), $admin['id'], $id]
        );
        if ($n === 0) {
            self::mustBeActive($id);
        }
        Audit::log('alert.resolved', 'business_alert', $id, [], $req, $admin);
    }

    private static function mustBeActive(int $id): void
    {
        $row = Db::one('SELECT status FROM business_alerts WHERE id = ?', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'Alert not found.');
        }
        throw new HttpException(409, 'This alert is already ' . $row['status'] . '.', 'conflict');
    }

    /**
     * @param array{status?:string,severity?:string,type?:string,page?:int} $f status: active (default) | resolved | all
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function list(array $f = []): array
    {
        $where = [];
        $args = [];
        $status = (string) ($f['status'] ?? 'active');
        if ($status === 'active') {
            $where[] = "status IN ('open', 'acknowledged')";
        } elseif ($status === 'resolved') {
            $where[] = "status = 'resolved'";
        }
        if (in_array($f['severity'] ?? '', self::SEVERITIES, true)) {
            $where[] = 'severity = ?';
            $args[] = $f['severity'];
        }
        if (isset($f['type']) && preg_match('/^[a-z0-9_.\-]{1,40}$/', (string) $f['type'])) {
            $where[] = 'alert_type = ?';
            $args[] = $f['type'];
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $per = 25;
        $total = (int) Db::val("SELECT COUNT(*) FROM business_alerts {$w}", $args);
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $rows = Db::all(
            "SELECT a.*, ak.username AS ack_by, rs.username AS res_by FROM business_alerts a LEFT JOIN admins ak ON ak.id = a.acknowledged_by LEFT JOIN admins rs ON rs.id = a.resolved_by {$w} "
            . "ORDER BY FIELD(a.status, 'open', 'acknowledged', 'resolved'), FIELD(a.severity, 'critical', 'warning', 'info'), a.last_detected_at DESC LIMIT {$per} OFFSET " . (($page - 1) * $per),
            $args
        );
        return ['rows' => array_map([self::class, 'shape'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array{critical:int,warning:int,info:int,total:int,unacknowledged:int} live alerts */
    public static function counts(): array
    {
        $out = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0, 'unacknowledged' => 0];
        foreach (Db::all("SELECT severity, status, COUNT(*) AS n FROM business_alerts WHERE status IN ('open', 'acknowledged') GROUP BY severity, status") as $r) {
            $out[$r['severity']] = ($out[$r['severity']] ?? 0) + (int) $r['n'];
            $out['total'] += (int) $r['n'];
            if ($r['status'] === 'open') {
                $out['unacknowledged'] += (int) $r['n'];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function shape(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'type' => $r['alert_type'], 'severity' => $r['severity'], 'title' => $r['title'], 'description' => $r['description'],
            'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'], 'status' => $r['status'], 'occurrences' => (int) $r['occurrences'],
            'first_detected_at' => Clock::iso($r['first_detected_at']), 'last_detected_at' => Clock::iso($r['last_detected_at']),
            'acknowledged_by' => $r['ack_by'], 'acknowledged_at' => Clock::iso($r['acknowledged_at']),
            'resolved_at' => Clock::iso($r['resolved_at']), 'resolved_by' => $r['res_by'], 'resolution' => $r['resolution'],
            'details' => $r['details_json'] !== null ? json_decode((string) $r['details_json'], true) : null,
        ];
    }

    // ── e-mail (optional, off by default) ──

    /** Settings keys (all editable in Admin → Watchdog). */
    public static function mailSettings(): array
    {
        return [
            'enabled'      => (bool) Settings::get('alerts.email_enabled', false),
            'to'           => (string) Settings::get('alerts.email_to', ''),
            'from'         => (string) Settings::get('alerts.email_from', ''),
            'min_severity' => in_array(Settings::get('alerts.email_min_severity', 'critical'), ['warning', 'critical'], true) ? (string) Settings::get('alerts.email_min_severity', 'critical') : 'critical',
            'cooldown_min' => max(5, min(1440, (int) Settings::get('alerts.email_cooldown_min', 60))),
        ];
    }

    /** True when a message was handed to the mail system for this alert (at most once per cooldown). */
    private static function maybeNotify(int $id, string $severity): bool
    {
        $m = self::mailSettings();
        if (!$m['enabled'] || array_search($severity, self::SEVERITIES, true) < array_search($m['min_severity'], self::SEVERITIES, true)) {
            return false;
        }
        $to = array_values(array_filter(array_map('trim', explode(',', $m['to'])), static fn(string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        if (!$to) {
            return false;
        }
        // win the right to notify atomically: two cron runs raising the same alert cannot both send
        $won = Db::exec(
            'UPDATE business_alerts SET last_notified_at = ? WHERE id = ? AND (last_notified_at IS NULL OR last_notified_at < ?)',
            [Clock::sql(), $id, Clock::sql(Clock::now() - $m['cooldown_min'] * 60)]
        );
        if ($won !== 1) {
            return false;
        }
        $a = Db::one('SELECT * FROM business_alerts WHERE id = ?', [$id]);
        if ($a === null) {
            return false;
        }
        $site = Settings::site()['name'];
        $subject = '[' . strtoupper((string) $a['severity']) . '] ' . $site . ': ' . $a['title'];
        $body = $a['title'] . "\n\n" . $a['description'] . "\n\nSeen " . $a['occurrences'] . ' time(s) since ' . Tz::label() . ' ' . Clock::iso($a['first_detected_at']) . ".\nOpen the alert: " . absolute_url('/admin/alerts') . "\n\n(This message contains no credentials and no customer data.)\n";
        $from = filter_var($m['from'], FILTER_VALIDATE_EMAIL) !== false ? $m['from'] : 'no-reply@' . (parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: 'localhost');
        $headers = 'From: ' . $from . "\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: smb-alerts";
        try {
            $send = self::$mailer ?? static fn(string $t, string $s, string $b, string $h): bool => @mail($t, '=?UTF-8?B?' . base64_encode($s) . '?=', $b, $h);
            $ok = (bool) $send(implode(',', $to), $subject, $body, $headers);
            if (!$ok) {
                Log::warning('Alert e-mail could not be handed to the mail system', ['alert' => $id]);
            }
            return $ok;
        } catch (\Throwable $e) {
            Log::exception($e, 'Alert e-mail failed', ['alert' => $id]);
            return false;
        }
    }
}
