<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\Money;

/**
 * AI advisory layer for adaptive pricing (master prompt, Adaptive Pricing §7 "AI insights"). Every number it is given was already
 * computed deterministically by App\Services\Pricing — it may only explain and suggest, never decide. Its reply is never used to
 * pick a winner, trigger a rollback or change a price; it is shown in the Learning History screen, clearly labelled as a
 * recommendation, separate from the automated decision.
 *
 * When no AI provider is configured (or `ai.use_for_pricing` is off), `rulesBasedInsight()` gives a plain, deterministic sentence
 * instead — the master prompt requires a clearly-labelled fallback, never a claim that a real model ran when none did.
 */
final class PricingAi
{
    private const MAX_LEN = 900;

    private const SYSTEM = <<<'TXT'
        You are a careful pricing analyst writing one short note for the owner of a small e-commerce shop, from numbers their own
        system already calculated. Reply with ONLY a single JSON object, no markdown fences and no other text, in exactly this shape:
        {"explanation": "1-3 sentences on what the numbers show", "recommendation": "one concrete, advisory next step", "confidence_note": "a short, honest statement of how much the sample size supports this"}
        Rules: use ONLY the numbers given to you — never invent, recalculate or restate a figure differently than given. Never claim
        a causal relationship merely because two numbers changed together. If the sample is small, say so plainly rather than
        sounding certain. This is advice, not a decision: never tell the owner you have changed anything, and never invent a sales,
        cost or conversion figure that was not given to you.
        TXT;

    /**
     * @param array{id:int,username:string,email:string,role:string} $admin
     * @return array{status:string,message:string,insight:?array<string,mixed>}
     */
    public static function explain(int $experimentId, array $admin, ?Request $req = null): array
    {
        $exp = Db::one('SELECT e.*, s.title AS service_title FROM pricing_experiments e JOIN services s ON s.id = e.service_id WHERE e.id = ?', [$experimentId])
            ?? throw new HttpException(404, 'Experiment not found.');
        $s = AiService::settings();
        if (!$s['available'] || !$s['use_for_pricing']) {
            return ['status' => 'none', 'message' => $s['available'] ? 'AI insights are switched off for pricing — turn them on in AI & Support settings.' : 'AI is not configured — set it up in AI & Support settings first.', 'insight' => null];
        }
        $payload = self::payload($exp);
        $r = AiService::complete('pricing', self::SYSTEM, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!$r->ok()) {
            return ['status' => 'unavailable', 'message' => 'The AI provider could not be reached right now.', 'insight' => null];
        }
        $parsed = self::parseAndValidate($r->text);
        if ($parsed === null) {
            return ['status' => 'invalid', 'message' => 'The provider’s reply was not in the expected shape, so nothing is shown.', 'insight' => null];
        }
        Audit::log('pricing.ai_insight', 'pricing_experiment', $experimentId, ['provider' => $s['provider'], 'model' => $s['model']], $req, $admin);
        return ['status' => 'ok', 'message' => 'AI insight generated.', 'insight' => $parsed];
    }

    /** A deterministic, rules-based note used whenever no AI provider explains this experiment — never claims to be AI. */
    public static function rulesBasedInsight(array $exp, ?array $control, ?array $treatment): array
    {
        $c = $control ?? ['purchases' => 0, 'qualified' => 0, 'contribution_paise' => 0];
        $t = $treatment ?? ['purchases' => 0, 'qualified' => 0, 'contribution_paise' => 0];
        $decision = (string) ($exp['decision'] ?? '');
        $sentence = match ($decision) {
            'winner' => 'The candidate price (' . Money::inr((int) $exp['candidate_paise'], true) . ') produced more contribution profit per qualified visitor than the control price (' . Money::inr((int) $exp['control_paise'], true) . '), with ' . $c['purchases'] . ' control and ' . $t['purchases'] . ' treatment purchases recorded.',
            'loser' => 'The candidate price under-performed the control price on contribution profit per qualified visitor.',
            'rolled_back' => 'This experiment was rolled back automatically: ' . (string) ($exp['rollback_reason'] ?? $exp['decision_reason'] ?? 'a safety threshold was crossed.'),
            default => 'There is not yet enough verified, qualified traffic to reach a confident conclusion (control ' . $c['qualified'] . ', treatment ' . $t['qualified'] . ' qualified visitors).',
        };
        return ['explanation' => $sentence, 'recommendation' => $decision === 'winner' ? 'Review and, if it still looks right, activate the candidate price from this screen.' : 'Keep measuring, or propose a different candidate price.', 'confidence_note' => 'Rules-based note — no AI model was used to write this.', 'source' => 'rules'];
    }

    /** @return array<string,mixed> */
    private static function payload(array $exp): array
    {
        $control = Db::one('SELECT * FROM pricing_experiment_cohorts WHERE experiment_id = ? AND cohort = ?', [$exp['id'], 'control']);
        $treatment = Db::one('SELECT * FROM pricing_experiment_cohorts WHERE experiment_id = ? AND cohort = ?', [$exp['id'], 'treatment']);
        $view = static fn(?array $c): array => $c === null ? ['assigned' => 0, 'qualified' => 0, 'purchases' => 0, 'revenue_paise' => 0, 'contribution_paise' => 0] : [
            'assigned' => (int) $c['assigned'], 'qualified' => (int) $c['qualified'], 'checkout_starts' => (int) $c['checkout_starts'],
            'purchases' => (int) $c['purchases'], 'revenue_paise' => (int) $c['revenue_paise'], 'contribution_paise' => (int) $c['contribution_paise'],
        ];
        return [
            'service' => $exp['service_title'], 'status' => $exp['status'], 'mode' => $exp['mode'],
            'control_price_paise' => (int) $exp['control_paise'], 'candidate_price_paise' => (int) $exp['candidate_paise'],
            'min_sample_per_cohort' => (int) $exp['min_sample_per_cohort'], 'profit_floor_paise' => (int) $exp['profit_floor_paise'],
            'decision' => $exp['decision'], 'decision_reason' => $exp['decision_reason'], 'rollback_reason' => $exp['rollback_reason'],
            'control' => $view($control), 'treatment' => $view($treatment),
        ];
    }

    /** @return array{explanation:string,recommendation:string,confidence_note:string}|null */
    private static function parseAndValidate(string $text): ?array
    {
        $text = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($text)));
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true, 8);
        if (!is_array($data)) {
            return null;
        }
        $field = static function (mixed $v) : ?string {
            if (!is_string($v)) {
                return null;
            }
            $v = trim($v);
            return $v !== '' && mb_strlen($v) <= self::MAX_LEN ? $v : null;
        };
        $explanation = $field($data['explanation'] ?? null);
        $recommendation = $field($data['recommendation'] ?? null);
        $confidence = $field($data['confidence_note'] ?? null);
        if ($explanation === null || $recommendation === null || $confidence === null) {
            return null;
        }
        return ['explanation' => $explanation, 'recommendation' => $recommendation, 'confidence_note' => $confidence, 'source' => 'ai', 'generated_at' => Clock::iso(Clock::sql())];
    }
}
