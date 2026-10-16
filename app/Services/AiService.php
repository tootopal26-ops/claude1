<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiProviderInterface;
use App\Ai\AiResult;
use App\Ai\AnthropicProvider;
use App\Ai\OpenAiCompatibleProvider;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Support\Clock;
use App\Support\NetGuard;

/**
 * The one optional AI provider the application may use (Admin → AI & Support, permission `ai.configure`, owner only — this decides
 * whether any data at all leaves the server for a third party, so it is kept away from `manager`).
 *
 * Design (see docs/AI-AND-SUPPORT.md):
 *   - AI is off by default and the application is fully correct without it (ReportAi and SupportAssistant both have a deterministic
 *     path that needs no provider at all).
 *   - A provider adapter (App\Ai\AiProviderInterface) keeps business logic independent of the vendor; two are shipped
 *     (Anthropic's Messages API, and the OpenAI-compatible chat-completions shape many gateways implement).
 *   - The API key is encrypted at rest (same AES-256-GCM as the payment gateway and SMM provider secrets), never returned by any
 *     API, never logged.
 *   - Every call is recorded in `ai_usage_logs` (feature, provider, model, status, token counts, latency, error *category*) —
 *     never the prompt, never the answer, never the key.
 *   - A call that cannot be made (disabled, unreachable, or an answer that fails validation) returns a typed result; callers
 *     (ReportAi, SupportAssistant) always have a non-AI fallback and never throw the customer- or admin-facing request out because
 *     of it.
 */
final class AiService
{
    public const PROVIDERS = ['none' => 'Not configured', 'anthropic' => 'Anthropic', 'openai_compatible' => 'OpenAI-compatible'];
    private const DEFAULT_BASE_URL = ['anthropic' => 'https://api.anthropic.com'];

    /** Test seam: fn(array $settings): AiProviderInterface. Null = build the real provider from the saved settings. */
    public static $providerFactory = null;

    /** @return array<string,mixed> never includes the key itself */
    public static function settings(): array
    {
        $enabled = (bool) Settings::get('ai.enabled', false);
        $provider = self::validProvider((string) Settings::get('ai.provider', 'none'));
        $model = (string) Settings::get('ai.model', '');
        $baseUrl = (string) Settings::get('ai.base_url', '');
        $hasKey = Settings::get('ai.api_key_enc', '') !== '';
        return [
            'enabled' => $enabled, 'provider' => $provider, 'model' => $model,
            'base_url' => $baseUrl !== '' ? $baseUrl : (self::DEFAULT_BASE_URL[$provider] ?? ''),
            'has_key' => $hasKey, 'use_for_reports' => (bool) Settings::get('ai.use_for_reports', false), 'use_for_support' => (bool) Settings::get('ai.use_for_support', false),
            'providers' => self::PROVIDERS, 'available' => $enabled && $provider !== 'none' && $model !== '' && $hasKey,
        ];
    }

    private static function validProvider(string $p): string
    {
        return isset(self::PROVIDERS[$p]) ? $p : 'none';
    }

    /**
     * Validated as a whole; nothing is saved if one field is wrong. A field left out of `$in` keeps its stored value.
     *
     * @param array<string,mixed> $in
     * @param array{id:int,username:string,email:string,role:string} $admin
     * @return array<string,mixed> the new settings()
     */
    public static function saveSettings(array $in, array $admin, ?Request $req = null): array
    {
        $cur = self::settings();
        $errors = [];
        $set = [];
        $provider = $cur['provider'];
        $model = $cur['model'];
        $hadKey = $cur['has_key'];
        $keyChanged = false;

        if (array_key_exists('enabled', $in)) {
            if (!is_bool($in['enabled'])) {
                $errors['enabled'] = 'Choose on or off.';
            } else {
                $set['ai.enabled'] = $in['enabled'];
            }
        }
        if (array_key_exists('provider', $in)) {
            if (!is_string($in['provider']) || !isset(self::PROVIDERS[$in['provider']])) {
                $errors['provider'] = 'Choose a known provider.';
            } else {
                $provider = $in['provider'];
                $set['ai.provider'] = $provider;
            }
        }
        if (array_key_exists('model', $in)) {
            $m = is_string($in['model']) ? trim($in['model']) : '';
            if (mb_strlen($m) > 80) {
                $errors['model'] = 'At most 80 characters.';
            } else {
                $model = $m;
                $set['ai.model'] = $m;
            }
        }
        if (array_key_exists('base_url', $in)) {
            $b = is_string($in['base_url']) ? trim($in['base_url']) : '';
            if ($b === '') {
                $set['ai.base_url'] = '';
            } else {
                try {
                    $set['ai.base_url'] = NetGuard::assertSafeUrl($b, 'AI provider base URL');
                } catch (\InvalidArgumentException $e) {
                    $errors['base_url'] = $e->getMessage();
                }
            }
        }
        if (array_key_exists('api_key', $in) && $in['api_key'] !== '') {
            $k = is_string($in['api_key']) ? trim($in['api_key']) : '';
            if ($k === '' || mb_strlen($k) > 400) {
                $errors['api_key'] = 'That does not look like a key.';
            } else {
                $set['ai.api_key_enc'] = Crypto::encrypt($k);
                $hadKey = true;
                $keyChanged = true;
            }
        } elseif (($in['clear_key'] ?? false) === true) {
            $set['ai.api_key_enc'] = '';
            $hadKey = false;
            $keyChanged = true;
        }
        foreach (['use_for_reports', 'use_for_support'] as $f) {
            if (array_key_exists($f, $in)) {
                if (!is_bool($in[$f])) {
                    $errors[$f] = 'Choose on or off.';
                } else {
                    $set['ai.' . $f] = $in[$f];
                }
            }
        }
        $willBeEnabled = $set['ai.enabled'] ?? $cur['enabled'];
        if ($willBeEnabled && $provider !== 'none') {
            if ($model === '') {
                $errors['model'] = $errors['model'] ?? 'Enter the model name this provider expects.';
            }
            if (!$hadKey) {
                $errors['api_key'] = $errors['api_key'] ?? 'Enter an API key before switching AI on.';
            }
            // (the RAW stored value, not $cur['base_url']: that is already defaulted for display and would wrongly carry over another provider's default)
            if ($provider === 'openai_compatible' && ($set['ai.base_url'] ?? (string) Settings::get('ai.base_url', '')) === '') {
                $errors['base_url'] = $errors['base_url'] ?? 'An OpenAI-compatible endpoint has no default — enter its base URL.';
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Some of the settings are not valid.', 'invalid', ['fields' => $errors]);
        }
        if ($set) {
            Settings::setMany($set, $admin['id']);
        }
        Audit::log('ai.settings_changed', 'settings', 'ai', [
            'enabled' => $willBeEnabled, 'provider' => $provider, 'model' => $model, 'key_changed' => $keyChanged,
            'use_for_reports' => $set['ai.use_for_reports'] ?? $cur['use_for_reports'], 'use_for_support' => $set['ai.use_for_support'] ?? $cur['use_for_support'],
        ], $req, $admin);
        return self::settings();
    }

    public static function available(): bool
    {
        return self::settings()['available'];
    }

    private static function buildProvider(array $s): ?AiProviderInterface
    {
        $key = Crypto::decrypt((string) Settings::get('ai.api_key_enc', ''));
        if ($key === null || $key === '') {
            return null;
        }
        return match ($s['provider']) {
            'anthropic' => new AnthropicProvider($s['base_url'], $key, $s['model']),
            'openai_compatible' => new OpenAiCompatibleProvider($s['base_url'], $key, $s['model']),
            default => null,
        };
    }

    /**
     * One call, for one feature ('report' | 'support' | 'test'). Never throws; always logs (except when AI is simply switched off,
     * which is not an attempt). `$maxTokens` bounds the reply length (and, for most providers, the cost).
     */
    public static function complete(string $feature, string $system, string $user, int $maxTokens = 700): AiResult
    {
        $s = self::settings();
        if (!$s['available']) {
            return new AiResult(AiResult::DISABLED, error: 'AI is not configured.');
        }
        try {
            $provider = self::$providerFactory !== null ? (self::$providerFactory)($s) : self::buildProvider($s);
        } catch (\Throwable $e) {
            $provider = null;
        }
        if ($provider === null) {
            return new AiResult(AiResult::DISABLED, error: 'AI is not configured.');
        }
        try {
            $r = $provider->complete($system, $user, $maxTokens);
        } catch (\Throwable $e) {
            $r = new AiResult(AiResult::UNREACHABLE, error: 'The AI provider call failed unexpectedly.');
        }
        self::logUsage($feature, $s['provider'], $s['model'], $r);
        return $r;
    }

    private static function logUsage(string $feature, string $provider, string $model, AiResult $r): void
    {
        $status = match (true) {
            $r->ok() => 'ok',
            $r->status === AiResult::INVALID => 'invalid',
            $r->httpStatus === 429 => 'limited',
            default => 'error',
        };
        $category = match (true) {
            $r->ok() => null,
            $r->status === AiResult::INVALID => 'format',
            $r->httpStatus === null => 'network',
            in_array($r->httpStatus, [401, 403], true) => 'auth',
            $r->httpStatus === 429 => 'rate_limit',
            $r->httpStatus >= 500 => 'server',
            default => 'network',
        };
        try {
            Db::insert('ai_usage_logs', [
                'feature' => substr($feature, 0, 24), 'provider' => substr($provider, 0, 24), 'model' => $model !== '' ? substr($model, 0, 60) : null,
                'status' => $status, 'http_status' => $r->httpStatus, 'input_tokens' => $r->inputTokens, 'output_tokens' => $r->outputTokens,
                'latency_ms' => $r->durationMs, 'error_category' => $category, 'created_at' => Clock::sql(),
            ]);
        } catch (\Throwable $e) {
            Log::exception($e, 'AI usage log write failed', ['feature' => $feature]);
        }
    }

    /** A trivial round trip so an admin can see the saved settings actually work. Rate-limited by the caller. */
    public static function testConnection(array $admin, ?Request $req = null): array
    {
        $r = self::complete('test', 'Reply with exactly one word: OK.', 'Say the word.', 16);
        Audit::log('ai.test', 'settings', 'ai', ['status' => $r->status], $req, $admin);
        return match ($r->status) {
            AiResult::OK => ['ok' => true, 'message' => 'The provider answered in ' . $r->durationMs . ' ms.'],
            AiResult::DISABLED => ['ok' => false, 'message' => 'AI is not fully configured — save a provider, model and API key first.'],
            AiResult::INVALID => ['ok' => false, 'message' => 'The provider answered, but not in a way this feature understands.'],
            default => ['ok' => false, 'message' => 'Could not reach the provider: ' . $r->error],
        };
    }

    /** Usage over the last `$days` days (admin monitoring; never the prompts or the answers). */
    public static function usage(int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $rows = Db::all(
            'SELECT feature, status, COUNT(*) AS n, AVG(latency_ms) AS avg_ms, SUM(COALESCE(input_tokens,0)) AS in_tok, SUM(COALESCE(output_tokens,0)) AS out_tok '
            . 'FROM ai_usage_logs WHERE created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY GROUP BY feature, status',
            [$days]
        );
        $byFeature = [];
        foreach ($rows as $r) {
            $f = (string) $r['feature'];
            $byFeature[$f] ??= ['feature' => $f, 'ok' => 0, 'error' => 0, 'invalid' => 0, 'limited' => 0, 'avg_ms' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0];
            $byFeature[$f][(string) $r['status']] = (int) $r['n'];
            $byFeature[$f]['calls'] += (int) $r['n'];
            $byFeature[$f]['input_tokens'] += (int) $r['in_tok'];
            $byFeature[$f]['output_tokens'] += (int) $r['out_tok'];
            $byFeature[$f]['avg_ms'] = max($byFeature[$f]['avg_ms'], (int) round((float) $r['avg_ms']));
        }
        return ['days' => $days, 'by_feature' => array_values($byFeature)];
    }
}
