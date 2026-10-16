<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Anthropic's Messages API (https://api.anthropic.com/v1/messages or a compatible base URL). One of two adapters shipped so the
 * business logic (ReportAi, SupportAssistant) is never written against one vendor — see App\Ai\AiProviderInterface.
 */
final class AnthropicProvider implements AiProviderInterface
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function label(): string
    {
        return 'Anthropic (' . $this->model . ')';
    }

    public function complete(string $system, string $user, int $maxTokens): AiResult
    {
        $r = HttpJsonClient::postJson(
            rtrim($this->apiUrl, '/') . '/v1/messages',
            ['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'],
            ['model' => $this->model, 'max_tokens' => max(16, min(4000, $maxTokens)), 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]],
            8, 25, 131_072, $this->apiKey
        );
        if (!$r['ok']) {
            return new AiResult(AiResult::UNREACHABLE, error: $r['error'], httpStatus: $r['http_status'], durationMs: $r['duration_ms']);
        }
        $text = '';
        foreach ((array) ($r['data']['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        if ($text === '') {
            return new AiResult(AiResult::INVALID, error: 'The provider’s reply had no text content.', httpStatus: $r['http_status'], durationMs: $r['duration_ms']);
        }
        $usage = $r['data']['usage'] ?? [];
        return new AiResult(
            AiResult::OK, text: $text, httpStatus: $r['http_status'],
            inputTokens: is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            outputTokens: is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            durationMs: $r['duration_ms'],
        );
    }
}
