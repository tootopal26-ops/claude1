<?php

declare(strict_types=1);

namespace App\Smm;

/**
 * Outcome of one SMM provider call.
 *
 * `kind` tells the caller what is SAFE to do next:
 *   ok            — the provider accepted the request;
 *   rejected      — the provider answered with an error (bad service id, no funds…).
 *                   Nothing was created, so a retry after fixing the cause is safe;
 *   unreachable   — we never got a request to the provider (DNS/connect/TLS failure).
 *                   Nothing was created; automatic retry is safe;
 *   ambiguous     — the request may or may not have been processed (timeout, 5xx,
 *                   garbled reply). NEVER retry automatically: that could order twice.
 */
final class SmmResponse
{
    public const OK = 'ok';
    public const REJECTED = 'rejected';
    public const UNREACHABLE = 'unreachable';
    public const AMBIGUOUS = 'ambiguous';

    /** @param array<string,mixed>|null $data */
    public function __construct(
        public readonly string $kind,
        public readonly ?array $data = null,
        public readonly ?int $httpStatus = null,
        public readonly string $error = '',
        public readonly int $durationMs = 0,
        public readonly string $rawBody = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->kind === self::OK;
    }
}
