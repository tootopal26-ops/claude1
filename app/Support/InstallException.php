<?php

declare(strict_types=1);

namespace App\Support;

/** Installation problem that can be shown to the person installing (never contains secrets). */
final class InstallException extends \RuntimeException
{
    /** @param array<string,string> $fields @param array<string,mixed> $extra */
    public function __construct(string $message, public readonly array $fields = [], public readonly string $errorCode = 'error', public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
