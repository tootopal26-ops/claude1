<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AdminSettings;
use App\Support\InstallException;
use App\Support\Installer;
use App\Support\Tz;

/**
 * Web installer. Reachable only until the site is installed, and only for someone who can read
 * storage/install-token.txt on the server — a stranger who finds a fresh upload cannot take it over.
 */
final class InstallController
{
    public function show(Request $req, array $p): Response
    {
        $this->guard();
        $token = Installer::token();                 // creates storage/install-token.txt on first visit
        $unlocked = $this->unlocked($req);
        $uri = (string) ($req->server['REQUEST_URI'] ?? '/install');
        $base = Installer::detectBasePath($uri);
        $host = (string) ($req->server['HTTP_HOST'] ?? 'localhost');
        $data = [
            'unlocked' => $unlocked, 'csrf' => $unlocked ? Installer::csrf() : '', 'tokenPath' => 'storage/install-token.txt',
            'requirements' => $unlocked ? Installer::requirements() : [], 'canContinue' => $unlocked && Installer::requirementsMet(),
            'defaults' => [
                'url' => Installer::suggestUrl($host, $req->isSecure() || ($req->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') . $base,
                'site_name' => 'SOCIAL MEDIA BOOSTER', 'timezone' => 'Asia/Kolkata', 'db_host' => 'localhost', 'db_port' => '3306',
            ],
            'zones' => AdminSettings::ZONES, 'root' => SMB_ROOT, 'php' => PHP_VERSION,
        ];
        $html = View::render('install/index', $data, 'layouts/install');
        return Response::html($html)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** POST /install/unlock {token} */
    public function unlock(Request $req, array $p): Response
    {
        $this->guard();
        $this->jsonOnly($req);
        $t = Installer::throttle($req->ip(), false);
        if (!$t['allowed']) {
            throw new HttpException(429, 'Too many wrong tokens. Wait a few minutes and try again.', 'rate_limited', ['retry_after' => $t['retry_after']]);
        }
        $given = trim((string) $req->input('token', ''));
        if ($given === '' || !hash_equals(Installer::token(), $given)) {
            Installer::throttle($req->ip(), true);
            throw new HttpException(403, 'That token is not correct. Copy it exactly from ' . 'storage/install-token.txt' . ' on your server.', 'bad_token');
        }
        $res = Response::json(['ok' => true]);
        $res->cookie(Installer::sessionCookie(), Installer::sessionValue(), Csrf::cookieOptions($req, time() + 7200, 'Strict', true));
        return $res;
    }

    /** POST /install/test-db */
    public function testDb(Request $req, array $p): Response
    {
        $this->authorise($req);
        $in = $req->json();
        $errors = array_intersect_key(Installer::validate($in + ['url' => 'https://x.test', 'site_name' => 'xx', 'admin_username' => 'abc', 'admin_email' => 'a@b.co', 'admin_password' => 'Zq9!x-Long-Passphrase']), array_flip(['db_host', 'db_port', 'db_name', 'db_user', 'db_pass']));
        if ($errors) {
            throw new HttpException(422, 'Please fix the highlighted fields.', 'invalid', ['fields' => $errors]);
        }
        $r = Installer::probeDatabase(trim((string) $in['db_host']), (int) ((string) ($in['db_port'] ?? '') === '' ? 3306 : $in['db_port']), trim((string) $in['db_name']), trim((string) $in['db_user']), (string) ($in['db_pass'] ?? ''));
        if (!$r['ok']) {
            throw new HttpException(422, (string) $r['error'], 'database', ['fields' => ['db_host' => (string) $r['error']]]);
        }
        return Response::json(['ok' => true, 'version' => $r['version'], 'message' => 'Connected to ' . $r['version'] . '. The database user can create tables.']);
    }

    /** POST /install/run */
    public function run(Request $req, array $p): Response
    {
        $this->authorise($req);
        @set_time_limit(180);
        try {
            $r = Installer::install($req->json());
        } catch (InstallException $e) {
            $status = match ($e->errorCode) { 'invalid', 'database' => 422, 'busy' => 409, 'installed' => 404, default => 500 };
            $err = ['code' => $e->errorCode, 'message' => $e->getMessage()] + ($e->fields ? ['fields' => $e->fields] : []) + $e->extra;
            return Response::json(['ok' => false, 'error' => $err], $status);
        }
        $res = Response::json([
            'ok' => true, 'admin_created' => $r['admin_created'], 'env_written' => $r['env_written'], 'url' => $r['url'],
            'login' => $r['url'] . '/admin/login', 'cron' => 'php ' . SMB_ROOT . '/bin/cron.php', 'cron_url' => $r['url'] . '/cron/run?key=' . $r['cron_key'],
            'https' => str_starts_with($r['url'], 'https://'), 'db_version' => $r['db_version'],
        ]);
        $res->cookie(Installer::sessionCookie(), '', Csrf::cookieOptions($req, 1, 'Strict', true));
        return $res;
    }

    // ── helpers ──

    private function guard(): void
    {
        if (Installer::isInstalled()) {
            throw new HttpException(404, 'Page not found.');
        }
    }

    private function unlocked(Request $req): bool
    {
        $c = $req->cookie(Installer::sessionCookie());
        return $c !== null && hash_equals(Installer::sessionValue(), $c);
    }

    private function jsonOnly(Request $req): void
    {
        if (!$req->sameOrigin()) {
            throw new HttpException(403, 'Cross-site request blocked.', 'bad_origin');
        }
        if (!$req->isJson() || $req->header('x-requested-with') !== 'smb') {
            throw new HttpException(415, 'Unsupported request.', 'unsupported_media_type');
        }
    }

    private function authorise(Request $req): void
    {
        $this->guard();
        $this->jsonOnly($req);
        if (!$this->unlocked($req)) {
            throw new HttpException(403, 'Enter the installation token first.', 'locked');
        }
        if (!hash_equals(Installer::csrf(), (string) $req->header('x-csrf-token', ''))) {
            throw new HttpException(403, 'Security token missing or expired. Reload the page and try again.', 'bad_csrf');
        }
    }
}
