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
                mb_substr($type, 0, 40), $severity, Jobs::clean($title, 160), Jobs::clean($description, 1000), $entityType, $entityId, $key, $key,
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
        $out = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0, 'unacknowledged' => 0, 'unacknowledged_critical' => 0];
        foreach (Db::all("SELECT severity, status, COUNT(*) AS n FROM business_alerts WHERE status IN ('open', 'acknowledged') GROUP BY severity, status") as $r) {
            $out[$r['severity']] = ($out[$r['severity']] ?? 0) + (int) $r['n'];
            $out['total'] += (int) $r['n'];
            if ($r['status'] === 'open') {
                $out['unacknowledged'] += (int) $r['n'];
                if ($r['severity'] === 'critical') {
                    $out['unacknowledged_critical'] += (int) $r['n'];
                }
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

    /**
     * Validate and save the e-mail settings. Only the keys that are sent change. Switching e-mail on needs at least one valid recipient.
     * The audit log records how many recipients there are — never the addresses.
     *
     * @param array<string,mixed> $in {enabled?, to?, from?, min_severity?, cooldown_min?}
     * @param array{id:int,username:string,email:string} $admin
     * @return array{enabled:bool,to:string,from:string,min_severity:string,cooldown_min:int}
     */
    public static function saveMailSettings(array $in, array $admin, ?Request $req = null): array
    {
        $cur = self::mailSettings();
        $errors = [];
        $set = [];
        $clean = static fn(string $s): string => mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s), 0, 60);
        if (array_key_exists('enabled', $in)) {
            if (!is_bool($in['enabled'])) {
                $errors['enabled'] = 'Choose on or off.';
            } else {
                $set['alerts.email_enabled'] = $in['enabled'];
            }
        }
        if (array_key_exists('to', $in)) {
            $raw = is_string($in['to']) ? trim($in['to']) : null;
            if ($raw === null || mb_strlen($raw) > 250) {
                $errors['to'] = 'Enter up to 5 e-mail addresses separated by commas.';
            } else {
                $list = $raw === '' ? [] : array_map('trim', explode(',', $raw));
                $bad = array_values(array_filter($list, static fn(string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) === false));
                if ($bad) {
                    $errors['to'] = '“' . $clean($bad[0]) . '” is not an e-mail address.';
                } elseif (count($list) > 5) {
                    $errors['to'] = 'Enter at most 5 addresses.';
                } else {
                    $set['alerts.email_to'] = implode(', ', $list);
                }
            }
        }
        if (array_key_exists('from', $in)) {
            $from = is_string($in['from']) ? trim($in['from']) : null;
            if ($from === null || ($from !== '' && (mb_strlen($from) > 120 || filter_var($from, FILTER_VALIDATE_EMAIL) === false))) {
                $errors['from'] = 'Enter one e-mail address, or leave this empty.';
            } else {
                $set['alerts.email_from'] = $from;
            }
        }
        if (array_key_exists('min_severity', $in)) {
            if (!in_array($in['min_severity'], ['warning', 'critical'], true)) {
                $errors['min_severity'] = 'Choose warning or critical.';
            } else {
                $set['alerts.email_min_severity'] = $in['min_severity'];
            }
        }
        if (array_key_exists('cooldown_min', $in)) {
            $v = $in['cooldown_min'];
            if ((!is_int($v) && !(is_string($v) && preg_match('/^\d{1,5}$/D', $v))) || (int) $v < 5 || (int) $v > 1440) {
                $errors['cooldown_min'] = 'Enter a number from 5 to 1440.';
            } else {
                $set['alerts.email_cooldown_min'] = (int) $v;
            }
        }
        $enabled = (bool) ($set['alerts.email_enabled'] ?? $cur['enabled']);
        $recipients = self::recipients((string) ($set['alerts.email_to'] ?? $cur['to']));
        if ($enabled && !$recipients && !isset($errors['to'])) {
            $errors['to'] = 'Add at least one recipient to switch e-mail on.';
        }
        if ($errors) {
            throw new HttpException(422, 'Some of the settings are not valid.', 'invalid', ['fields' => $errors]);
        }
        if ($set) {
            Settings::setMany($set, $admin['id']);
            $now = self::mailSettings();
            Audit::log('alerts.mail_settings_changed', 'settings', 'alerts', [
                'enabled' => $now['enabled'], 'recipients' => count(self::recipients($now['to'])), 'min_severity' => $now['min_severity'], 'cooldown_min' => $now['cooldown_min'], 'sender_set' => $now['from'] !== '',
            ], $req, $admin);
        }
        return self::mailSettings();
    }

    /**
     * One message to the saved recipients, so the owner can see whether the hosting provider's mail() works. It is sent even while e-mail alerts
     * are off, and it says only what happened: handed to the mail system, or refused. Arrival is up to the provider.
     *
     * @param array{id:int,username:string,email:string} $admin
     * @return array{sent:bool,recipients:int,message:string}
     */
    public static function sendTest(array $admin, ?Request $req = null): array
    {
        $m = self::mailSettings();
        $to = self::recipients($m['to']);
        if (!$to) {
            throw new HttpException(422, 'Save at least one recipient before sending a test.', 'invalid', ['fields' => ['to' => 'Add a recipient and save first.']]);
        }
        $site = Settings::site()['name'];
        $body = "This is a test message from the Business Watchdog of {$site}.\n\nIf you can read it, alert e-mails reach this address. No alert is open because of this message.\nAlerts: " . absolute_url('/admin/alerts') . "\n\n(This message contains no credentials and no customer data.)\n";
        $ok = self::deliver($to, '[TEST] ' . $site . ': alert e-mail test', $body, $m, 0);
        Audit::log('alerts.test_email', 'settings', 'alerts', ['recipients' => count($to), 'handed_to_mail_system' => $ok], $req, $admin);
        return [
            'sent' => $ok, 'recipients' => count($to),
            'message' => $ok ? 'Handed to the mail system for ' . count($to) . ' recipient' . (count($to) === 1 ? '' : 's') . '. Whether and when it arrives is up to your hosting provider’s mail service — check the inbox and the spam folder.'
                : 'The mail system refused the message (PHP mail() returned false). Ask your hosting provider whether mail() is enabled for this account, or leave e-mail alerts off.',
        ];
    }

    /** @return list<string> the valid addresses of a comma-separated list */
    private static function recipients(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $list)), static fn(string $a): bool => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
    }

    /** @param list<string> $to @param array<string,mixed> $m */
    private static function deliver(array $to, string $subject, string $body, array $m, int $alertId): bool
    {
        $from = filter_var($m['from'], FILTER_VALIDATE_EMAIL) !== false ? $m['from'] : 'no-reply@' . (parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: 'localhost');
        $headers = 'From: ' . $from . "\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: smb-alerts";
        try {
            $send = self::$mailer ?? static fn(string $t, string $s, string $b, string $h): bool => @mail($t, '=?UTF-8?B?' . base64_encode($s) . '?=', $b, $h);
            $ok = (bool) $send(implode(',', $to), $subject, $body, $headers);
            if (!$ok) {
                Log::warning('E-mail could not be handed to the mail system', ['alert' => $alertId]);
            }
            return $ok;
        } catch (\Throwable $e) {
            Log::exception($e, 'E-mail failed', ['alert' => $alertId]);
            return false;
        }
    }

    /** True when a message was handed to the mail system for this alert (at most once per cooldown). */
    private static function maybeNotify(int $id, string $severity): bool
    {
        $m = self::mailSettings();
        if (!$m['enabled'] || array_search($severity, self::SEVERITIES, true) < array_search($m['min_severity'], self::SEVERITIES, true)) {
            return false;
        }
        $to = self::recipients($m['to']);
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
        return self::deliver($to, $subject, $body, $m, $id);
    }
}
