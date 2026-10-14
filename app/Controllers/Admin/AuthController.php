<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AdminAuth;
use App\Services\Audit;
use App\Services\Settings;

/** Sign in / sign out. Works with fetch (JSON) and, as a fallback, a plain form post. */
final class AuthController extends BaseController
{
    public function page(Request $req, array $p): Response
    {
        if (AdminAuth::resolve($req) !== null) {
            return Response::redirect(url('/admin/dashboard'));
        }
        return $this->render($req, $this->notice($req), 200, self::safeNext($req->queryString('next', '', 120)));
    }

    public function login(Request $req, array $p): Response
    {
        if (!$req->sameOrigin()) {
            throw new HttpException(403, 'Cross-site request blocked.', 'bad_origin');
        }
        $json = $req->isJson();
        $next = self::safeNext((string) $req->input('next', ''));

        if (!Csrf::verifyPre($req)) {
            return $this->failure($req, $json, 'Your sign-in form expired. Please reload the page and try again.', 403, 'bad_csrf', $next);
        }
        $r = AdminAuth::attempt(
            $req,
            (string) $req->input('username', ''),
            (string) $req->input('password', ''),
            filter_var($req->input('remember', false), FILTER_VALIDATE_BOOLEAN),
        );
        if (!$r['ok']) {
            $status = isset($r['retry_after']) ? 429 : 401;
            return $this->failure($req, $json, (string) $r['error'], $status, $status === 429 ? 'rate_limited' : 'bad_credentials', $next, (int) ($r['retry_after'] ?? 0));
        }

        $target = $r['admin']['must_change_password'] ? url('/admin/security') . '?force=1' : ($next !== '' ? url($next) : url('/admin/dashboard'));
        $res = $json ? Response::json(['ok' => true, 'redirect' => $target]) : Response::redirect($target);
        AdminAuth::setCookie($res, $req, (string) $r['token'], (bool) $req->input('remember', false), (int) $r['life']);
        $res->cookie(Csrf::preCookieName(), '', Csrf::cookieOptions($req, 1));   // single use
        return $res->header('Cache-Control', 'no-store');
    }

    public function logout(Request $req, array $p): Response
    {
        $admin = $this->admin($req);
        Audit::log('admin.logout', 'admin', $admin['id'], [], $req, $admin);
        AdminAuth::logout($req);
        $to = url('/admin/login') . '?out=1';
        $res = $req->wantsJson() ? Response::json(['ok' => true, 'redirect' => $to]) : Response::redirect($to);
        AdminAuth::clearCookie($res, $req);
        return $res->header('Cache-Control', 'no-store');
    }

    // ── helpers ──

    private function failure(Request $req, bool $json, string $message, int $status, string $code, string $next, int $retryAfter = 0): Response
    {
        if ($json) {
            $err = ['code' => $code, 'message' => $message];
            if ($retryAfter > 0) {
                $err['retry_after'] = $retryAfter;
            }
            $res = Response::json(['ok' => false, 'error' => $err], $status);
            if ($retryAfter > 0) {
                $res->header('Retry-After', (string) $retryAfter);
            }
            return $res;
        }
        return $this->render($req, ['type' => 'error', 'text' => $message], $status, $next);
    }

    /** @param array{type:string,text:string}|null $notice */
    private function render(Request $req, ?array $notice, int $status, string $next): Response
    {
        [$formToken, $cookie] = Csrf::issuePre();
        $site = Settings::site();
        $html = View::render('admin/login', [
            'site' => $site, 'csrf' => $formToken, 'notice' => $notice, 'next' => $next,
            'title' => 'Sign in — ' . $site['name'], 'page' => 'login', 'scripts' => ['admin-login'],
        ], 'layouts/admin-bare');
        $res = Response::html($html, $status)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
        $res->cookie(Csrf::preCookieName(), $cookie, Csrf::cookieOptions($req, 0, 'Lax', true));
        return $res;
    }

    /** @return array{type:string,text:string}|null */
    private function notice(Request $req): ?array
    {
        if ($req->queryString('expired') === '1') {
            return ['type' => 'warning', 'text' => 'Your session expired. Please sign in again.'];
        }
        if ($req->queryString('out') === '1') {
            return ['type' => 'success', 'text' => 'You have been signed out.'];
        }
        return null;
    }

    /** Only same-site admin paths are accepted as a post-login destination (no open redirect). */
    public static function safeNext(string $next): string
    {
        return preg_match('#^/admin/[a-z]{3,20}$#D', $next) === 1 ? $next : '';
    }
}
