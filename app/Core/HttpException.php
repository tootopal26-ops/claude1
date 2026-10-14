<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thrown anywhere in a request to short-circuit with an HTTP error.
 * The Kernel renders it as JSON (API paths) or an HTML error page.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string,mixed> $extra  merged into the JSON error body (e.g. field errors) */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly string $errorCode = '',
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Please sign in to continue.',
            403 => 'You do not have permission to do that.',
            404 => 'We could not find what you were looking for.',
            405 => 'Method not allowed.',
            409 => 'That request conflicts with the current state.',
            410 => 'This is no longer available.',
            413 => 'That upload is too large.',
            415 => 'Unsupported content type.',
            422 => 'Some of the information provided is not valid.',
            429 => 'Too many requests. Please slow down and try again shortly.',
            503 => 'Service temporarily unavailable.',
            default => 'Something went wrong. Please try again.',
        };
    }
}
