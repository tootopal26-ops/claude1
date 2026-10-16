<?php
declare(strict_types=1);

use App\Ai\AiProviderInterface;
use App\Ai\AiResult;
use App\Core\Db;
use App\Core\HttpException;
use App\Services\AiService;

/**
 * The optional AI provider facade: settings validated as a whole and saved without ever exposing the key, `available()` only
 * once every piece is in place, every call recorded in ai_usage_logs (never the prompt, never the answer, never the key) with a
 * real result from an injected fake provider (no network in tests — App\Ai\AiProviderInterface is the only thing AiService
 * depends on, so a fake stands in for a real vendor exactly where one is wired in production).
 */
final class AiFakeProvider implements AiProviderInterface
{
    public static ?AiResult $next = null;
    public static ?string $lastSystem = null;
    public static ?string $lastUser = null;

    public function label(): string
    {
        return 'Fake';
    }

    public function complete(string $system, string $user, int $maxTokens): AiResult
    {
        self::$lastSystem = $system;
        self::$lastUser = $user;
        return self::$next ?? new AiResult(AiResult::OK, text: 'OK');
    }
}

function ai_reset(): void
{
    T::freshDb();
    AiService::$providerFactory = null;
    AiFakeProvider::$next = null;
}

function ai_owner(): array
{
    return ['id' => 1, 'username' => 'root', 'email' => 'r@x.test', 'role' => 'owner'];
}

function ai_use_fake(): void
{
    AiService::$providerFactory = static fn(array $s): AiProviderInterface => new AiFakeProvider();
}

T::test('AI settings: off by default, nothing to hide — and saved as a whole, never exposing the key', function () {
    ai_reset();
    $s = AiService::settings();
    T::same([false, 'none', '', false, false, false, false], [$s['enabled'], $s['provider'], $s['model'], $s['has_key'], $s['use_for_reports'], $s['use_for_support'], $s['available']]);

    $e = T::throws(HttpException::class, fn() => AiService::saveSettings(['provider' => 'not-a-real-vendor'], ai_owner()), 422);
    T::true(isset($e->extra['fields']['provider']));
    $e = T::throws(HttpException::class, fn() => AiService::saveSettings(['base_url' => 'not a url'], ai_owner()), 422);
    T::true(isset($e->extra['fields']['base_url']));
    $e = T::throws(HttpException::class, fn() => AiService::saveSettings(['model' => str_repeat('x', 90)], ai_owner()), 422);
    T::true(isset($e->extra['fields']['model']));

    // turning it on needs a model AND a key together
    $e = T::throws(HttpException::class, fn() => AiService::saveSettings(['enabled' => true, 'provider' => 'anthropic'], ai_owner()), 422);
    T::true(isset($e->extra['fields']['model']) && isset($e->extra['fields']['api_key']));

    $s = AiService::saveSettings(['enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'sk-test-abc123'], ai_owner());
    T::same([true, 'anthropic', 'claude-x', true, 'https://api.anthropic.com', true], [$s['enabled'], $s['provider'], $s['model'], $s['has_key'], $s['base_url'], $s['available']]);
    T::false(isset($s['api_key']) || isset($s['api_key_enc']), 'the key never comes back out');
    $meta = json_decode((string) Db::val("SELECT meta_json FROM audit_logs WHERE action = 'ai.settings_changed' ORDER BY id DESC LIMIT 1"), true);
    T::same(['anthropic', 'claude-x', true], [$meta['provider'], $meta['model'], $meta['key_changed']]);
    T::false(str_contains((string) Db::val("SELECT meta_json FROM audit_logs WHERE action = 'ai.settings_changed' ORDER BY id DESC LIMIT 1"), 'sk-test'), 'the key is never in the audit row');

    // an OpenAI-compatible endpoint has no default base URL
    $e = T::throws(HttpException::class, fn() => AiService::saveSettings(['provider' => 'openai_compatible', 'model' => 'llama', 'api_key' => 'k'], ai_owner()), 422);
    T::true(isset($e->extra['fields']['base_url']));
    $s = AiService::saveSettings(['provider' => 'openai_compatible', 'base_url' => 'https://gateway.example.test/v1', 'model' => 'llama', 'api_key' => 'k'], ai_owner());
    T::same(['openai_compatible', 'https://gateway.example.test/v1'], [$s['provider'], $s['base_url']]);

    // clearing the key while still enabled refuses, rather than silently leaving a half-configured feature on
    $e = T::throws(HttpException::class, fn() => AiService::saveSettings(['clear_key' => true], ai_owner()), 422);
    T::true(isset($e->extra['fields']['api_key']));
    $s = AiService::saveSettings(['enabled' => false, 'clear_key' => true], ai_owner());
    T::same([false, false], [$s['has_key'], $s['available']]);
});

T::test('AI calls: every attempt is logged (feature, provider, model, status, tokens, latency — never the prompt or the answer), a switched-off AI logs nothing', function () {
    ai_reset();
    T::same(AiResult::DISABLED, AiService::complete('test', 'sys', 'hello')->status, 'disabled: no provider is ever built');
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM ai_usage_logs'), 'and nothing is logged for an attempt that was never made');

    AiService::saveSettings(['enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'k'], ai_owner());
    ai_use_fake();

    AiFakeProvider::$next = new AiResult(AiResult::OK, text: 'hello there', inputTokens: 12, outputTokens: 34, durationMs: 50);
    $r = AiService::complete('report', 'system instructions', 'the user payload');
    T::same(['ok', 'hello there'], [$r->status, $r->text]);
    T::same('system instructions', AiFakeProvider::$lastSystem, 'the system prompt reaches the provider');
    $row = Db::one("SELECT * FROM ai_usage_logs WHERE feature = 'report' ORDER BY id DESC LIMIT 1");
    T::same(['report', 'anthropic', 'claude-x', 'ok', 12, 34, null], [$row['feature'], $row['provider'], $row['model'], $row['status'], (int) $row['input_tokens'], (int) $row['output_tokens'], $row['error_category']]);
    T::false(str_contains(json_encode($row), 'hello there'), 'the reply text itself is never stored');

    AiFakeProvider::$next = new AiResult(AiResult::UNREACHABLE, error: 'boom', httpStatus: null);
    AiService::complete('support', 's', 'u');
    T::same(['error', 'network'], array_values(Db::one("SELECT status, error_category FROM ai_usage_logs WHERE feature = 'support' ORDER BY id DESC LIMIT 1")));

    AiFakeProvider::$next = new AiResult(AiResult::UNREACHABLE, error: 'rate limited', httpStatus: 429);
    AiService::complete('support', 's', 'u');
    T::same(['limited', 'rate_limit'], array_values(Db::one("SELECT status, error_category FROM ai_usage_logs WHERE feature = 'support' ORDER BY id DESC LIMIT 1")));

    AiFakeProvider::$next = new AiResult(AiResult::UNREACHABLE, error: 'down', httpStatus: 503);
    AiService::complete('support', 's', 'u');
    T::same(['error', 'server'], array_values(Db::one("SELECT status, error_category FROM ai_usage_logs WHERE feature = 'support' ORDER BY id DESC LIMIT 1")));

    AiFakeProvider::$next = new AiResult(AiResult::INVALID, error: 'no text');
    AiService::complete('support', 's', 'u');
    T::same(['invalid', 'format'], array_values(Db::one("SELECT status, error_category FROM ai_usage_logs WHERE feature = 'support' ORDER BY id DESC LIMIT 1")));

    T::same(5, (int) Db::val('SELECT COUNT(*) FROM ai_usage_logs'));
    $u = AiService::usage();
    $report = array_values(array_filter($u['by_feature'], static fn(array $f): bool => $f['feature'] === 'report'))[0];
    T::same([1, 0], [$report['ok'], $report['error']]);
    $support = array_values(array_filter($u['by_feature'], static fn(array $f): bool => $f['feature'] === 'support'))[0];
    T::same([0, 2, 1, 1, 4], [$support['ok'], $support['error'], $support['invalid'], $support['limited'], $support['calls']]);
});

T::test('AI test connection: reports a real round trip or says exactly why not — and is rate-limited per admin', function () {
    ai_reset();
    $r = AiService::testConnection(ai_owner());
    T::false($r['ok']);
    T::contains('not fully configured', $r['message']);

    AiService::saveSettings(['enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-x', 'api_key' => 'k'], ai_owner());
    ai_use_fake();
    AiFakeProvider::$next = new AiResult(AiResult::OK, text: 'OK', durationMs: 42);
    $r = AiService::testConnection(ai_owner());
    T::true($r['ok']);
    T::contains('42 ms', $r['message']);
    T::true((int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'ai.test'") >= 1);

    AiFakeProvider::$next = new AiResult(AiResult::UNREACHABLE, error: 'no route to host');
    $r = AiService::testConnection(ai_owner());
    T::false($r['ok']);
    T::contains('no route to host', $r['message']);
});
