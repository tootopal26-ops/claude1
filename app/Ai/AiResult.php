<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The outcome of one call to an AI provider. `status` tells the caller exactly what happened and is always safe to store or show:
 *
 *   ok           — the provider answered; `text` is its raw reply (the caller still has to validate its *content*, e.g. as JSON).
 *   disabled     — AI is switched off, or no provider/key is configured. Nothing was called.
 *   unreachable  — the request could not be completed (network, TLS, timeout, auth, a non-2xx the provider gave back).
 *   invalid      — the provider answered but the reply could not be parsed into the shape the caller expected.
 *
 * `text` and `error` never contain the API key (the client that builds this scrubs it first).
 */
final class AiResult
{
    public const OK = 'ok';
    public const DISABLED = 'disabled';
    public const UNREACHABLE = 'unreachable';
    public const INVALID = 'invalid';

    public function __construct(
        public readonly string $status,
        public readonly string $text = '',
        public readonly string $error = '',
        public readonly ?int $httpStatus = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly int $durationMs = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }
}
