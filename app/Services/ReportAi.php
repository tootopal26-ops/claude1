<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiResult;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;

/**
 * Optional AI commentary on an already-generated daily report (master prompt §6.2). The deterministic report ([DailyReport]) is
 * complete and correct without this — this only adds a few plain-language sentences *about* numbers it is never allowed to change.
 *
 *   - The AI is sent a small, pre-aggregated summary of the day (totals, deltas, service names, alert titles) — never an order id,
 *     a customer link, a payment reference or a raw database row.
 *   - Its reply must be strict JSON in a fixed shape; anything else (prose, a different shape, a field too long) is `invalid` and
 *     nothing is shown — the deterministic report is never affected.
 *   - The validated reply is stored apart from the figures (`daily_reports.ai_*`) and is cleared automatically whenever a
 *     regeneration changes a figure (DailyReport::generate), so it can never go stale next to numbers it no longer describes.
 *   - It is never used to decide the figures themselves, and the system prompt tells the model to call out an uncertain or merely
 *     correlated change rather than assert a cause.
 */
final class ReportAi
{
    private const MAX_ITEMS = 6;
    private const MAX_ITEM_LEN = 240;
    private const MAX_SUMMARY_LEN = 700;

    private const SYSTEM = <<<'TXT'
        You are a careful business analyst writing a short note for the owner of a small e-commerce shop, from numbers their own
        system already calculated. Reply with ONLY a single JSON object, no markdown fences and no other text, in exactly this shape:
        {"summary": "1-3 sentences overall", "changes": ["notable change 1", ...], "causes": ["a plausible reason, phrased as possible, not certain", ...],
        "next_steps": ["a concrete, advisory suggestion", ...], "uncertainties": ["a figure or gap the owner should treat with caution", ...]}
        Rules: use ONLY the numbers given to you — never invent, recalculate or restate a figure differently than given. Each list has at
        most 6 short items. Never claim a causal relationship merely because two numbers changed together — call it a possible
        correlation, not a cause. If the data given is too thin to say much, say so plainly in "uncertainties" rather than guessing.
        Do not suggest anything that would require refunding, cancelling or contacting a specific customer — these numbers are
        aggregated and name no one.
        TXT;

    /**
     * @param array{id:int,username:string,email:string,role:string} $admin
     * @return array{status:string,message:string}
     */
    public static function summarize(string $day, array $admin, ?Request $req = null): array
    {
        $report = DailyReport::get($day) ?? throw new HttpException(404, 'There is no report for that day.');
        $s = AiService::settings();
        if (!$s['available'] || !$s['use_for_reports']) {
            return ['status' => 'none', 'message' => $s['available'] ? 'AI commentary is switched off for reports — turn it on in AI & Support settings.' : 'AI is not configured — set it up in AI & Support settings first.'];
        }
        $payload = self::payload($report['snapshot']);
        $r = AiService::complete('report', self::SYSTEM, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!$r->ok()) {
            self::store($day, 'unavailable', $s, null);
            return ['status' => 'unavailable', 'message' => 'The AI provider could not be reached right now. The report itself is unaffected.'];
        }
        $parsed = self::parseAndValidate($r->text);
        if ($parsed === null) {
            self::store($day, 'invalid', $s, null);
            return ['status' => 'invalid', 'message' => 'The provider’s reply was not in the expected shape, so nothing was shown. The report itself is unaffected.'];
        }
        self::store($day, 'ok', $s, $parsed);
        Audit::log('report.ai_generated', 'daily_report', $day, ['provider' => $s['provider'], 'model' => $s['model']], $req, $admin);
        return ['status' => 'ok', 'message' => 'AI commentary added.'];
    }

    /** @return array<string,mixed> aggregated, customer-free numbers — see the class note */
    private static function payload(array $snapshot): array
    {
        $metrics = [];
        foreach ((array) ($snapshot['metrics'] ?? []) as $key => $m) {
            $metrics[$key] = ['label' => $m['label'], 'value' => $m['value'], 'unit' => $m['unit'], 'basis' => $m['basis']];
        }
        $svc = static fn(array $rows): array => array_map(
            static fn(array $r): array => ['title' => $r['title'] ?? $r['code'] ?? '', 'orders' => $r['orders'] ?? null, 'contribution_paise' => $r['contribution'] ?? null, 'margin_tenths_pct' => $r['margin_tenths'] ?? null, 'why' => $r['why'] ?? null],
            array_slice($rows, 0, 5)
        );
        $alerts = array_map(
            static fn(array $a): array => ['title' => $a['title'], 'severity' => $a['severity'], 'occurrences' => $a['occurrences']],
            array_slice((array) ($snapshot['alerts']['unresolved'] ?? []), 0, 8)
        );
        return [
            'date' => $snapshot['date'] ?? null, 'quality' => $snapshot['quality'] ?? null, 'metrics' => $metrics,
            'comparison_with_day_before' => array_map(
                static fn(array $r): array => ['label' => $r['label'], 'this_day' => $r['value'], 'day_before' => $r['previous'], 'delta' => $r['delta']],
                (array) ($snapshot['comparison']['rows'] ?? [])
            ),
            'most_profitable_services' => $svc((array) ($snapshot['services']['most_profitable'] ?? [])),
            'loss_making_services' => $svc((array) ($snapshot['services']['loss_making'] ?? [])),
            'services_with_unknown_cost' => $svc((array) ($snapshot['services']['missing_cost'] ?? [])),
            'unresolved_alerts' => $alerts,
            'payment_and_api_failures' => $snapshot['failures'] ?? null,
        ];
    }

    /** @return array{summary:string,changes:list<string>,causes:list<string>,next_steps:list<string>,uncertainties:list<string>}|null */
    private static function parseAndValidate(string $text): ?array
    {
        $text = trim($text);
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true, 8);
        if (!is_array($data) || !isset($data['summary']) || !is_string($data['summary'])) {
            return null;
        }
        $summary = trim($data['summary']);
        if ($summary === '' || mb_strlen($summary) > self::MAX_SUMMARY_LEN) {
            return null;
        }
        $list = static function (mixed $v): ?array {
            if (!is_array($v)) {
                return null;
            }
            $out = [];
            foreach (array_slice($v, 0, self::MAX_ITEMS) as $item) {
                if (!is_string($item)) {
                    return null;
                }
                $item = trim($item);
                if ($item === '' || mb_strlen($item) > self::MAX_ITEM_LEN) {
                    return null;
                }
                $out[] = $item;
            }
            return $out;
        };
        $changes = $list($data['changes'] ?? []);
        $causes = $list($data['causes'] ?? []);
        $nextSteps = $list($data['next_steps'] ?? []);
        $uncertainties = $list($data['uncertainties'] ?? []);
        if ($changes === null || $causes === null || $nextSteps === null || $uncertainties === null) {
            return null;
        }
        return ['summary' => $summary, 'changes' => $changes, 'causes' => $causes, 'next_steps' => $nextSteps, 'uncertainties' => $uncertainties];
    }

    private static function store(string $day, string $status, array $settings, ?array $validated): void
    {
        Db::exec(
            'UPDATE daily_reports SET ai_status = ?, ai_provider = ?, ai_model = ?, ai_summary = ?, ai_generated_at = ? WHERE report_date = ?',
            [$status, $settings['provider'], $settings['model'] !== '' ? $settings['model'] : null, $validated !== null ? json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, Clock::sql(), $day]
        );
    }
}
