<?php

declare(strict_types=1);

namespace App\Payments;

final class ConnectionTestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly int $durationMs = 0,
        /** "ok" | "warning" | "error" for the UI. */
        public readonly string $level = 'ok',
    ) {
    }
}
