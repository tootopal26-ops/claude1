<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\AdminAuth;

/**
 * Route middleware (callables: fn(Request, callable $next): Response).
 */
final class Middleware
{
    /** Admin HTML pages: redirect to the login form when not signed in. */
    public static function adminPage(Request $req, callable $next): Response
    {
        $auth = AdminAuth::resolve($req);
        if ($auth === null) {
            $to = $req->path === '/admin' || $req->path === '/admin/' ? '' : '?next=' . rawurlencode($req->path);
            return Response::redirect(url('/admin/login') . $to);
        }
        $req->attributes['admin'] = $auth['admin'];
        $req->attributes['auth'] = $auth;

        if ($auth['admin']['must_change_password'] && !str_starts_with($req->path, '/admin/security') && $req->method === 'GET') {
            return Response::redirect(url('/admin/security') . '?force=1');
        }
        $res = $next($req);
        $res->headers['Cache-Control'] = 'no-store';
        $res->headers['X-Robots-Tag'] = 'noindex, nofollow';
        return $res;
    }

    /** Admin JSON API: authenticated + CSRF-protected for unsafe methods. */
    public static function adminApi(Request $req, callable $next): Response
    {
        $auth = AdminAuth::resolve($req);
        if ($auth === null) {
            throw new HttpException(401, 'Your session has expired. Please sign in again.', 'unauthenticated');
        }
        if (!in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if (!$req->sameOrigin()) {
                throw new HttpException(403, 'Cross-site request blocked.', 'bad_origin');
            }
            if (!Csrf::verifySession($req, $auth['session']['csrf_secret'])) {
                throw new HttpException(403, 'Security token missing or expired. Reload the page and try again.', 'bad_csrf');
            }
        }
        // A temporary password must be replaced before anything else works.
        if ($auth['admin']['must_change_password'] && !in_array($req->path, ['/admin/api/account/password', '/admin/api/account/sessions', '/admin/api/status', '/admin/logout'], true)) {
            throw new HttpException(403, 'Please change your temporary password first.', 'password_change_required');
        }
        $req->attributes['admin'] = $auth['admin'];
        $req->attributes['auth'] = $auth;
        $res = $next($req);
        $res->headers['Cache-Control'] = 'no-store';
        return $res;
    }

    /**
     * Public JSON endpoints that change state (checkout, tracking beacons).
     * Browsers cannot send a cross-site request with a JSON content type or a
     * custom header without a CORS preflight, which this app never grants.
     */
    public static function publicJsonPost(Request $req, callable $next): Response
    {
        if (!$req->sameOrigin()) {
            throw new HttpException(403, 'Cross-site request blocked.', 'bad_origin');
        }
        if (!$req->isJson() || $req->header('x-requested-with') !== 'smb') {
            throw new HttpException(415, 'Unsupported request.', 'unsupported_media_type');
        }
        return $next($req);
    }

    /** Beacons use navigator.sendBeacon (no custom headers) — JSON content type + same origin only. */
    public static function beaconPost(Request $req, callable $next): Response
    {
        if (!$req->sameOrigin()) {
            throw new HttpException(403, 'Cross-site request blocked.', 'bad_origin');
        }
        if (!$req->isJson()) {
            throw new HttpException(415, 'Unsupported request.', 'unsupported_media_type');
        }
        return $next($req);
    }

    public static function noIndex(Request $req, callable $next): Response
    {
        $res = $next($req);
        $res->headers['X-Robots-Tag'] = 'noindex, nofollow';
        return $res;
    }
}
