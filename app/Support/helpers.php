<?php
/**
 * Global helper functions (kept deliberately small). Everything else lives in
 * namespaced classes.
 */

declare(strict_types=1);

use App\Core\Env;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('e')) {
    /** HTML-escape for output. Use for EVERY dynamic value in templates. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_path')) {
    /** Sub-folder the app is served from ("" when installed at the domain root). */
    function base_path(): string
    {
        static $base = null;
        if ($base === null) {
            $configured = (string) env('APP_URL', '');
            if ($configured === '' && PHP_SAPI !== 'cli') {
                // Fresh upload (no .env yet): the installer URL tells us where the app lives, e.g. /shop/install → /shop
                $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
                $path = preg_match('#^(.*?)/install(?:/|$)#', $uri, $m) ? $m[1] : '';
            } else {
                $path = (string) (parse_url($configured, PHP_URL_PATH) ?? '');
            }
            $base = rtrim($path, '/');
        }
        return $base;
    }
}

if (!function_exists('url')) {
    /** Root-relative URL inside this app (respects a sub-folder install). */
    function url(string $path = '/'): string
    {
        return base_path() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('absolute_url')) {
    /** Absolute URL built from APP_URL (never from the Host header). */
    function absolute_url(string $path = '/'): string
    {
        $root = rtrim((string) env('APP_URL', ''), '/');
        $root = preg_replace('#' . preg_quote(base_path(), '#') . '$#', '', $root) ?? $root;
        return $root . url($path);
    }
}

if (!function_exists('asset')) {
    /** URL of a file under /public/assets with a cache-busting ?v=mtime. */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = SMB_PUBLIC . '/assets/' . $path;
        $v = is_file($file) ? (string) filemtime($file) : '0';
        return url('assets/' . $path) . '?v=' . $v;
    }
}

if (!function_exists('upload_url')) {
    /** URL of an admin-uploaded file (path stored relative to /uploads). */
    function upload_url(?string $relative): ?string
    {
        if ($relative === null || $relative === '') {
            return null;
        }
        $file = SMB_PUBLIC . '/uploads/' . $relative;
        $v = is_file($file) ? (string) filemtime($file) : '0';
        return url('uploads/' . $relative) . '?v=' . $v;
    }
}

if (!function_exists('icon')) {
    /** Inline SVG icon from the sprite. */
    function icon(string $name, string $class = ''): string
    {
        return '<svg class="icon ' . e($class) . '" aria-hidden="true" focusable="false"><use href="'
            . e(url('assets/img/icons.svg')) . '?v=' . e((string) (@filemtime(SMB_PUBLIC . '/assets/img/icons.svg') ?: 0))
            . '#i-' . e($name) . '"/></svg>';
    }
}

if (!function_exists('json_script')) {
    /** Safe JSON island for the page's JS (not executed; CSP-friendly). */
    function json_script(string $id, mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return '<script type="application/json" id="' . e($id) . '">' . $json . '</script>';
    }
}

if (!function_exists('is_cli_request')) {
    function is_cli_request(): bool
    {
        return PHP_SAPI === 'cli';
    }
}
