<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Turns exceptions into responses: JSON for API-style requests, a styled HTML
 * page for browsers. Internal details are only ever shown when APP_DEBUG=true
 * AND APP_ENV=local.
 */
final class ErrorPages
{
    public static function fromHttp(HttpException $e, Request $req): Response
    {
        if ($req->wantsJson()) {
            $err = ['code' => $e->errorCode !== '' ? $e->errorCode : self::codeFor($e->status), 'message' => $e->getMessage()] + $e->extra;
            $res = Response::json(['ok' => false, 'error' => $err], $e->status);
        } else {
            $res = self::page($e->status, $e->getMessage());
        }
        foreach ($e->headers as $k => $v) {
            $res->header($k, $v);
        }
        return $res;
    }

    public static function fromThrowable(\Throwable $e, Request $req): Response
    {
        Log::exception($e);
        $detail = Env::debug() ? get_class($e) . ': ' . $e->getMessage() : '';
        $status = $e instanceof \PDOException ? 503 : 500;
        $message = $status === 503
            ? 'The service is temporarily unavailable. Please try again in a moment.'
            : 'Something went wrong. Please try again.';
        if ($req->wantsJson()) {
            $err = ['code' => $status === 503 ? 'unavailable' : 'server_error', 'message' => $message];
            if ($detail !== '') {
                $err['debug'] = $detail;
            }
            return Response::json(['ok' => false, 'error' => $err], $status);
        }
        return self::page($status, $message, $detail);
    }

    public static function page(int $status, string $message, string $detail = ''): Response
    {
        $titles = [
            403 => 'Access denied', 404 => 'Page not found', 405 => 'Not allowed', 410 => 'No longer available',
            429 => 'Slow down', 500 => 'Something went wrong', 503 => 'Temporarily unavailable',
        ];
        try {
            $site = \App\Services\Settings::site();
        } catch (\Throwable) {
            $site = ['name' => 'SOCIAL MEDIA BOOSTER', 'logo_url' => asset('img/logo-mark.svg'), 'favicon_url' => asset('img/favicon.svg'), 'accent_from' => '#ff2d87', 'accent_to' => '#ff3b3b'];
        }
        $html = View::render('errors/error', [
            'status'  => $status,
            'heading' => $titles[$status] ?? 'Error',
            'message' => $message,
            'detail'  => $detail,
            'site'    => $site,
            'title'   => ($titles[$status] ?? 'Error') . ' — ' . $site['name'],
            'noindex' => true,
        ], 'layouts/plain');
        return Response::html($html, $status)->header('Cache-Control', 'no-store');
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
            405 => 'method_not_allowed', 409 => 'conflict', 410 => 'gone', 413 => 'too_large',
            415 => 'unsupported_media_type', 422 => 'invalid', 429 => 'rate_limited', 503 => 'unavailable',
            default => 'error',
        };
    }
}
