<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env reader (KEY=VALUE, # comments, optional quotes).
 * Real environment variables win over the file so hosts that can set them
 * (SetEnv, PHP-FPM pools, Docker) keep working.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    /** @var array<string,string> explicit runtime overrides (tests, installer) — highest priority */
    private static array $overrides = [];
    private static bool $loaded = false;

    public static function load(string $file): void
    {
        self::$loaded = true;
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    // one pass: \\ → \, \" → ", \n → newline (sequential str_replace would mis-decode "\\n")
                    $value = strtr($value, ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n"]);
                }
            } else {
                // strip trailing inline comment:  KEY=value # comment
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }
        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        if (array_key_exists($key, self::$values) && self::$values[$key] !== '') {
            return self::$values[$key];
        }
        return $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** Override a value at runtime (tests, installer). Wins over the real environment and the .env file. */
    public static function set(string $key, string $value): void
    {
        self::$overrides[$key] = $value;
    }

    public static function isLocal(): bool
    {
        return self::get('APP_ENV', 'production') === 'local';
    }

    public static function isProduction(): bool
    {
        return !self::isLocal();
    }

    public static function debug(): bool
    {
        return self::isLocal() && self::bool('APP_DEBUG', false);
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }
}
