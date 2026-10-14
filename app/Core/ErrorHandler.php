<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Production-safe error handling: never leak PHP errors to visitors, always
 * log them, and turn real bugs (warnings and up) into exceptions so they cannot
 * pass silently. Notices/deprecations are logged only, so a newer PHP version on
 * the host cannot take the site down over a harmless deprecation.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', Env::debug() ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            $soft = in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT], true);
            if ($soft && !Env::isLocal()) {
                Log::warning('PHP notice: ' . $str, ['file' => str_replace(SMB_ROOT . '/', '', $file) . ':' . $line]);
                return true;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Log::error('Fatal error: ' . $err['message'], ['file' => str_replace(SMB_ROOT . '/', '', $err['file']) . ':' . $err['line']]);
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/html; charset=utf-8');
                    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title><p style="font-family:sans-serif;padding:2rem">Something went wrong. Please try again in a moment.</p>';
                }
            }
        });
    }
}
