<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Tiny JSON-lines logger → storage/logs/app-YYYY-MM-DD.log.
 * Anything that looks like a secret is redacted before it is written.
 */
final class Log
{
    private const SECRET_KEY = '/(key|secret|password|passwd|token|authorization|cookie|signature)/i';

    public static function debug(string $message, array $context = []): void
    {
        if (Env::isLocal()) {
            self::write('debug', $message, $context);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function exception(\Throwable $e, string $message = 'Unhandled exception', array $context = []): void
    {
        self::write('error', $message, $context + [
            'exception' => get_class($e),
            'error'     => $e->getMessage(),
            'file'      => str_replace(SMB_ROOT . '/', '', $e->getFile()) . ':' . $e->getLine(),
            'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ]);
    }

    /** @param array<string,mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        $dir = SMB_ROOT . '/storage/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            @error_log("[smb][$level] $message");
            return;
        }
        $line = json_encode([
            'ts'      => gmdate('c'),
            'level'   => $level,
            'message' => $message,
            'context' => self::redact($context),
            'req'     => PHP_SAPI === 'cli' ? 'cli' : (($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @param mixed $data */
    private static function redact(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 5) {
            return '…';
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = (is_string($k) && preg_match(self::SECRET_KEY, $k)) ? '[redacted]' : self::redact($v, $depth + 1);
            }
            return $out;
        }
        if (is_string($data) && strlen($data) > 2000) {
            return substr($data, 0, 2000) . '…[truncated]';
        }
        return $data;
    }
}
