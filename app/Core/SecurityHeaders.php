<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Global security headers, applied to every response.
 *
 * The CSP is strict on purpose: no inline scripts, no inline styles, no
 * third-party origins. Pages hand data to JS through <script type="application/json">
 * islands, and styling comes from the compiled stylesheet only.
 */
final class SecurityHeaders
{
    public static function apply(Response $res, Request $req): void
    {
        @header_remove('X-Powered-By');

        $h = &$res->headers;
        $h['X-Content-Type-Options'] = 'nosniff';
        $h['X-Frame-Options'] = 'DENY';
        $h['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        $h['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()';
        $h['Cross-Origin-Opener-Policy'] = 'same-origin';
        $h['Cross-Origin-Resource-Policy'] = 'same-origin';
        $h['X-Permitted-Cross-Domain-Policies'] = 'none';

        if ($req->isSecure()) {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        $type = $h['Content-Type'] ?? '';
        if (str_starts_with($type, 'text/html')) {
            $csp = [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self'",
                "img-src 'self' data: blob:",
                "font-src 'self'",
                "connect-src 'self'",
                "manifest-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "object-src 'none'",
            ];
            if ($req->isSecure()) {
                $csp[] = 'upgrade-insecure-requests';
            }
            $h['Content-Security-Policy'] = implode('; ', $csp);
        }

        // Dynamic pages must never be cached by shared caches unless the page said otherwise.
        if (!isset($h['Cache-Control'])) {
            $h['Cache-Control'] = 'no-cache';
        }
    }
}
