<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Tz;

/**
 * Actual advertising spend — what the ad platform billed — entered by an admin or imported from a pasted CSV report.
 *
 * Two figures exist for advertising and they are NEVER added together:
 *   ACTUAL    ad_daily_spend: the amount billed for a day (optionally split by campaign and platform), with the GST kept apart.
 *   ESTIMATE  qualified visitors × the CPC in force when each qualified (visitor_days.cpc_paise): a model, not a bill.
 * For a day that has any actual spend (not voided) the day's advertising cost IS the actual spend and the estimate is ignored for that day —
 * even if only one campaign was entered: enter every campaign that ran, or one row without a campaign for the day's total. A day with no actual
 * spend keeps the estimate, and says so. The rule is applied in one place (Analytics::computeDays) so every screen agrees.
 *
 * There is no live link to an advertising platform (none is documented for this shop): spend is entered or pasted from the platform's report.
 */
final class AdSpend
{
    public const MAX_PAISE = 1_000_000_000;           // ₹1 crore in one entry
    public const MAX_IMPORT_ROWS = 500;

    /** Lower-case, a-z 0-9 _ . - and space, at most 80 characters: the same cleaning the visitor tracker applies to utm_campaign, so spend and visits meet. */
    public static function clean(mixed $v, int $max = 80): string
    {
        $v = is_string($v) ? strtolower(trim($v)) : '';
        return substr((string) preg_replace('/[^a-z0-9_.\- ]/', '', $v), 0, $max);
    }

    // ═══════════════════════════ reading ════════════════════════════════════

    /**
     * Counted actual spend per day, only for days that have any (a day missing from the result uses the estimate).
     *
     * @return array<string,int>
     */
    public static function byDay(string $from, string $to): array
    {
        $gst = Finance::settings()['ad_gst'] === 'cost';
        $out = [];
        foreach (Db::all(
            'SELECT spend_day AS d, SUM(amount_paise' . ($gst ? ' + gst_paise' : '') . ') AS s FROM ad_daily_spend WHERE voided_at IS NULL AND spend_day BETWEEN ? AND ? GROUP BY spend_day',
            [$from, $to]
        ) as $r) {
            $out[(string) $r['d']] = (int) $r['s'];
        }
        return $out;
    }

    /**
     * @param array{from?:string,to?:string,campaign?:string,include_voided?:bool,page?:int} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per:int,sum_paise:int,gst_paise:int}
     */
    public static function entries(array $f): array
    {
        $per = 25;
        $where = ['1 = 1'];
        $args = [];
        if (!empty($f['from']) && Tz::isDay((string) $f['from'])) {
            $where[] = 'a.spend_day >= ?';
            $args[] = $f['from'];
        }
        if (!empty($f['to']) && Tz::isDay((string) $f['to'])) {
            $where[] = 'a.spend_day <= ?';
            $args[] = $f['to'];
        }
        if (isset($f['campaign']) && $f['campaign'] !== '') {
            $where[] = 'a.campaign = ?';
            $args[] = $f['campaign'] === '(none)' ? '' : self::clean($f['campaign']);
        }
        if (empty($f['include_voided'])) {
            $where[] = 'a.voided_at IS NULL';
        }
        $w = implode(' AND ', $where);
        $total = (int) Db::val("SELECT COUNT(*) FROM ad_daily_spend a WHERE {$w}", $args);
        $sums = Db::one("SELECT COALESCE(SUM(a.amount_paise), 0) AS s, COALESCE(SUM(a.gst_paise), 0) AS g FROM ad_daily_spend a WHERE {$w} AND a.voided_at IS NULL", $args) ?? ['s' => 0, 'g' => 0];
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $rows = Db::all("SELECT a.*, ad.username FROM ad_daily_spend a LEFT JOIN admins ad ON ad.id = a.entered_by WHERE {$w} ORDER BY a.spend_day DESC, a.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $args);
        return ['rows' => array_map([self::class, 'shape'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'sum_paise' => (int) $sums['s'], 'gst_paise' => (int) $sums['g']];
    }

    /** The campaign names that have a spend entry ('' = the day's total, not split by campaign), for the filter. @return list<string> */
    public static function campaigns(): array
    {
        return array_map('strval', Db::col('SELECT DISTINCT campaign FROM ad_daily_spend ORDER BY campaign'));
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function shape(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'day' => (string) $r['spend_day'], 'campaign' => (string) $r['campaign'], 'platform' => (string) $r['platform'],
            'amount_paise' => (int) $r['amount_paise'], 'gst_paise' => (int) $r['gst_paise'], 'currency' => (string) $r['currency'],
            'clicks' => $r['clicks'] !== null ? (int) $r['clicks'] : null, 'impressions' => $r['impressions'] !== null ? (int) $r['impressions'] : null,
            'source' => (string) $r['source'], 'note' => $r['note'], 'by' => $r['username'] ?? null, 'at' => Clock::iso($r['imported_at']),
            'voided' => $r['voided_at'] !== null, 'voided_at' => Clock::iso($r['voided_at']),
        ];
    }

    // ═══════════════════════════ writing ════════════════════════════════════

    /**
     * Validate one entry (the same rules for the form and for every CSV line).
     *
     * @param array<string,mixed> $in
     * @return array{day:string,campaign:string,platform:string,amount:int,gst:int,clicks:?int,impressions:?int,note:?string}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $day = trim((string) ($in['day'] ?? ''));
        if (!Tz::isDay($day) || $day > Tz::today() || $day < '2020-01-01') {
            $errors['day'] = 'Choose a date that is not in the future (YYYY-MM-DD).';
        }
        $campaign = self::clean($in['campaign'] ?? '');
        $platform = self::clean($in['platform'] ?? '', 24);
        $money = static function (string $field, mixed $raw, bool $required) use (&$errors): int {
            $raw = is_string($raw) || is_int($raw) || is_float($raw) ? trim((string) $raw) : '';
            if ($raw === '') {
                if ($required) {
                    $errors[$field] = 'Enter the amount in rupees (0 is allowed: nothing was spent).';
                }
                return 0;
            }
            try {
                $p = Money::parseRupees($raw);
                if ($p > self::MAX_PAISE) {
                    $errors[$field] = 'That amount is too large.';
                }
                return $p;
            } catch (\InvalidArgumentException) {
                $errors[$field] = 'Enter rupees with at most two decimals.';
                return 0;
            }
        };
        $amount = $money('amount', $in['amount'] ?? null, true);
        $gst = $money('gst', $in['gst'] ?? '', false);
        $count = static function (string $field, mixed $raw) use (&$errors): ?int {
            if ($raw === null || $raw === '') {
                return null;
            }
            if (!is_numeric($raw) || (string) (int) $raw !== trim((string) $raw) || (int) $raw < 0 || (int) $raw > 2_000_000_000) {
                $errors[$field] = 'Enter a whole number, or leave it empty when the platform did not report it.';
                return null;
            }
            return (int) $raw;
        };
        $clicks = $count('clicks', $in['clicks'] ?? null);
        $impr = $count('impressions', $in['impressions'] ?? null);
        if ($clicks !== null && $impr !== null && $clicks > $impr) {
            $errors['clicks'] = 'Clicks cannot be more than impressions.';
        }
        $note = trim(mb_substr((string) ($in['note'] ?? ''), 0, 255));
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        return ['day' => $day, 'campaign' => $campaign, 'platform' => $platform, 'amount' => $amount, 'gst' => $gst, 'clicks' => $clicks, 'impressions' => $impr, 'note' => $note !== '' ? $note : null];
    }

    /**
     * Enter (or correct) the actual spend of one day / campaign / platform. The same key replaces the earlier entry — the old value is in the audit log.
     *
     * @param array<string,mixed> $in {day, campaign?, platform?, amount, gst?, clicks?, impressions?, note?}
     * @param array{id:int,username:string,email:string} $admin
     * @return array<string,mixed>
     */
    public static function save(array $in, ?Request $req, array $admin, string $source = 'manual'): array
    {
        $v = self::validate($in);
        $id = self::upsert($v, $source, $admin['id'], $old);
        Audit::log($old === null ? 'ads.spend_added' : 'ads.spend_changed', 'ad_spend', $id, [
            'day' => $v['day'], 'campaign' => $v['campaign'], 'platform' => $v['platform'], 'amount' => Money::plain($v['amount']), 'gst' => Money::plain($v['gst']),
            'was' => $old === null ? null : ['amount' => Money::plain((int) $old['amount_paise']), 'gst' => Money::plain((int) $old['gst_paise']), 'voided' => $old['voided_at'] !== null],
        ], $req, $admin);
        Analytics::markDirty($v['day']);
        return self::one($id);
    }

    /**
     * @param array{day:string,campaign:string,platform:string,amount:int,gst:int,clicks:?int,impressions:?int,note:?string} $v
     * @param array<string,mixed>|null $old
     */
    private static function upsert(array $v, string $source, int $adminId, ?array &$old = null): int
    {
        $old = Db::one('SELECT * FROM ad_daily_spend WHERE spend_day = ? AND campaign = ? AND platform = ?', [$v['day'], $v['campaign'], $v['platform']]);
        $now = Clock::sql();
        Db::exec(
            'INSERT INTO ad_daily_spend (spend_day, campaign, platform, amount_paise, gst_paise, currency, clicks, impressions, source, note, entered_by, imported_at, voided_at) '
            . "VALUES (?, ?, ?, ?, ?, 'INR', ?, ?, ?, ?, ?, ?, NULL) ON DUPLICATE KEY UPDATE amount_paise = VALUES(amount_paise), gst_paise = VALUES(gst_paise), clicks = VALUES(clicks), "
            . 'impressions = VALUES(impressions), source = VALUES(source), note = VALUES(note), entered_by = VALUES(entered_by), imported_at = VALUES(imported_at), voided_at = NULL',
            [$v['day'], $v['campaign'], $v['platform'], $v['amount'], $v['gst'], $v['clicks'], $v['impressions'], $source, $v['note'], $adminId, $now]
        );
        return (int) Db::val('SELECT id FROM ad_daily_spend WHERE spend_day = ? AND campaign = ? AND platform = ?', [$v['day'], $v['campaign'], $v['platform']]);
    }

    /** @param array{id:int,username:string,email:string} $admin @return array<string,mixed> */
    public static function void(int $id, string $reason, ?Request $req, array $admin): array
    {
        $reason = trim(mb_substr($reason, 0, 200));
        if (mb_strlen($reason) < 3) {
            throw new HttpException(422, 'Say why (at least 3 characters).', 'invalid', ['fields' => ['reason' => 'Required.']]);
        }
        $day = null;
        Db::tx(static function () use ($id, &$day): void {
            $r = Db::one('SELECT * FROM ad_daily_spend WHERE id = ? FOR UPDATE', [$id]) ?? throw new HttpException(404, 'Entry not found.');
            if ($r['voided_at'] !== null) {
                throw new HttpException(409, 'That entry is already voided.', 'conflict');
            }
            $day = (string) $r['spend_day'];
            Db::update('ad_daily_spend', ['voided_at' => Clock::sql()], 'id = ?', [$id]);
        });
        Audit::log('ads.spend_voided', 'ad_spend', $id, ['reason' => $reason], $req, $admin);
        if ($day !== null) {
            Analytics::markDirty($day);
        }
        return self::one($id);
    }

    /** @return array<string,mixed> */
    public static function one(int $id): array
    {
        $r = Db::one('SELECT a.*, ad.username FROM ad_daily_spend a LEFT JOIN admins ad ON ad.id = a.entered_by WHERE a.id = ?', [$id]) ?? throw new HttpException(404, 'Entry not found.');
        return self::shape($r);
    }

    // ═══════════════════════════ CSV import ═════════════════════════════════

    /**
     * Paste a report from the advertising platform. Columns (comma, semicolon or tab separated; the header line is optional):
     *   date, campaign, platform, spend, gst, clicks, impressions     — spend and gst in rupees; campaign, platform, gst, clicks, impressions may be empty.
     * All or nothing: one bad line rejects the whole paste and names the line. A dry run validates and reports what would be saved.
     *
     * @param array{id:int,username:string,email:string} $admin
     * @return array{rows:int,created:int,changed:int,total_paise:int,dry_run:bool,message:string}
     */
    public static function import(string $csv, bool $dry, ?Request $req, array $admin): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $lines = array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));
        if (!$lines) {
            throw new HttpException(422, 'Paste the report first.', 'invalid', ['fields' => ['csv' => 'Nothing to import.']]);
        }
        if (preg_match('/^\s*(date|day)\b/i', $lines[0])) {
            array_shift($lines);
        }
        if (count($lines) > self::MAX_IMPORT_ROWS) {
            throw new HttpException(422, 'At most ' . self::MAX_IMPORT_ROWS . ' lines per import.', 'invalid', ['fields' => ['csv' => 'Too many lines.']]);
        }
        $parsed = [];
        $errors = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $n = $i + 1;
            $cols = str_getcsv($line, str_contains($line, "\t") ? "\t" : (substr_count($line, ';') > substr_count($line, ',') ? ';' : ','));
            $cols = array_pad(array_map('trim', $cols), 7, '');
            try {
                $v = self::validate(['day' => $cols[0], 'campaign' => $cols[1], 'platform' => $cols[2], 'amount' => $cols[3], 'gst' => $cols[4], 'clicks' => $cols[5], 'impressions' => $cols[6]]);
                $key = $v['day'] . '|' . $v['campaign'] . '|' . $v['platform'];
                if (isset($seen[$key])) {
                    $errors[] = 'Line ' . $n . ': the same day, campaign and platform appears on line ' . $seen[$key] . '.';
                    continue;
                }
                $seen[$key] = $n;
                $parsed[] = $v;
            } catch (HttpException $e) {
                $errors[] = 'Line ' . $n . ': ' . implode(' ', array_values($e->extra['fields'] ?? [$e->getMessage()]));
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Nothing was imported. ' . implode(' ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? ' (and ' . (count($errors) - 5) . ' more)' : ''), 'invalid', ['fields' => ['csv' => implode("\n", array_slice($errors, 0, 20))]]);
        }
        $created = 0;
        $changed = 0;
        $total = 0;
        $days = [];
        $apply = static function () use ($parsed, $dry, $admin, &$created, &$changed, &$total, &$days): void {
            foreach ($parsed as $v) {
                $total += $v['amount'] + $v['gst'];
                $days[$v['day']] = true;
                $exists = Db::val('SELECT COUNT(*) FROM ad_daily_spend WHERE spend_day = ? AND campaign = ? AND platform = ? AND voided_at IS NULL', [$v['day'], $v['campaign'], $v['platform']]);
                $exists ? $changed++ : $created++;
                if (!$dry) {
                    self::upsert($v, 'import', $admin['id']);
                }
            }
        };
        $dry ? $apply() : Db::tx($apply);
        if (!$dry) {
            Audit::log('ads.spend_imported', 'ad_spend', null, ['rows' => count($parsed), 'created' => $created, 'changed' => $changed, 'total' => Money::plain($total)], $req, $admin);
            foreach (array_keys($days) as $d) {
                Analytics::markDirty($d);
            }
        }
        return [
            'rows' => count($parsed), 'created' => $created, 'changed' => $changed, 'total_paise' => $total, 'dry_run' => $dry,
            'message' => ($dry ? 'Checked: would save ' : 'Saved ') . count($parsed) . ' line' . (count($parsed) === 1 ? '' : 's') . ' (' . $created . ' new, ' . $changed . ' replacing an earlier entry) totalling ' . Money::inr($total, true) . '.' . ($dry ? ' Nothing was changed.' : ''),
        ];
    }
}
