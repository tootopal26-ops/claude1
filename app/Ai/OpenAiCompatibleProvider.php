<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The "OpenAI-compatible chat completions" shape (`POST {base}/chat/completions`, bearer token) that many hosted and
 * self-hosted model gateways implement — the second of the two adapters shipped, so an admin is not locked to one vendor.
 */
final class OpenAiCompatibleProvider implements AiProviderInterface
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function label(): string
    {
        return 'OpenAI-compatible (' . $this->model . ')';
    }

    public function complete(string $system, string $user, int $maxTokens): AiResult
    {
        $r = HttpJsonClient::postJson(
            rtrim($this->apiUrl, '/') . '/chat/completions',
            ['Authorization' => 'Bearer ' . $this->apiKey],
            ['model' => $this->model, 'max_tokens' => max(16, min(4000, $maxTokens)), 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]],
            8, 25, 131_072, $this->apiKey
        );
        if (!$r['ok']) {
            return new AiResult(AiResult::UNREACHABLE, error: $r['error'], httpStatus: $r['http_status'], durationMs: $r['duration_ms']);
        }
        $text = (string) ($r['data']['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            return new AiResult(AiResult::INVALID, error: 'The provider’s reply had no text content.', httpStatus: $r['http_status'], durationMs: $r['duration_ms']);
        }
        $usage = $r['data']['usage'] ?? [];
        return new AiResult(
            AiResult::OK, text: $text, httpStatus: $r['http_status'],
            inputTokens: is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : null,
            outputTokens: is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : null,
            durationMs: $r['duration_ms'],
        );
    }
}
