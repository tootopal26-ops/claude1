<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Settings;
use App\Support\Tz;

/**
 * Admin HTML pages. Each one is a light server-rendered shell (navigation, headings,
 * filter controls, loading skeletons); the data arrives from /admin/api/* as JSON.
 */
final class PageController extends BaseController
{
    /** page key → [title, view, group] */
    public const PAGES = [
        'dashboard'   => ['Dashboard', 'admin/dashboard'],
        'orders'      => ['Orders', 'admin/orders'],
        'payments'    => ['Payments', 'admin/payments'],
        'services'    => ['Services', 'admin/services'],
        'website'     => ['Website', 'admin/website'],
        'gateway'     => ['Payment gateway', 'admin/gateway'],
        'smm'         => ['SMM API', 'admin/smm'],
        'advertising' => ['Advertising', 'admin/advertising'],
        'security'    => ['Security', 'admin/security'],
        'audit'       => ['Audit log', 'admin/audit'],
        'system'      => ['System', 'admin/system'],
    ];

    public function index(Request $req, array $p): Response
    {
        return Response::redirect(url('/admin/dashboard'));
    }

    public function show(Request $req, array $p): Response
    {
        $page = (string) ($p['page'] ?? '');
        if (!isset(self::PAGES[$page])) {
            throw new \App\Core\HttpException(404, 'Page not found.');
        }
        [$title, $view] = self::PAGES[$page];
        $auth = $this->auth($req);
        $site = Settings::site();
        $content = View::render($view, ['site' => $site, 'admin' => $auth['admin'], 'force' => $req->queryString('force') === '1']);
        $html = View::render('layouts/admin', [
            'content' => $content, 'site' => $site, 'admin' => $auth['admin'], 'csrf' => $auth['csrf'], 'page' => $page,
            'title' => $title . ' — ' . $site['name'] . ' Admin', 'pageTitle' => $title, 'tz' => Tz::name(), 'scripts' => ['admin'],
        ]);
        return Response::html($html);
    }
}
