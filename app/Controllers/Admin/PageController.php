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
    /** page key → [title, view, permission needed to open it (null = every signed-in admin)] */
    public const PAGES = [
        'dashboard'      => ['Dashboard', 'admin/dashboard', 'dashboard.view'],
        'finance'        => ['Finance', 'admin/finance', 'finance.view'],
        'ads'            => ['Ads Intelligence', 'admin/ads', 'ads.view'],
        'alerts'         => ['Business alerts', 'admin/alerts', 'alerts.view'],
        'reports'        => ['Daily reports', 'admin/reports', 'reports.view'],
        'pricing'        => ['Adaptive pricing', 'admin/pricing', 'pricing.view'],
        'orders'         => ['Orders', 'admin/orders', 'orders.view'],
        'recovery'       => ['Order recovery', 'admin/recovery', 'orders.view'],
        'payments'       => ['Payments', 'admin/payments', 'payments.view'],
        'payment-checks' => ['Payment checks', 'admin/payment-checks', 'payments.view'],
        'support'        => ['Support', 'admin/support', 'support.view'],
        'services'       => ['Services', 'admin/services', 'catalog.manage'],
        'tags'           => ['Tags & Badges', 'admin/tags', 'catalog.manage'],
        'website'        => ['Website', 'admin/website', 'catalog.manage'],
        'gateway'        => ['Payment gateway', 'admin/gateway', 'integrations.manage'],
        'smm'            => ['SMM API', 'admin/smm', 'integrations.manage'],
        'advertising'    => ['Advertising', 'admin/advertising', 'ads.view'],
        'security'       => ['Security', 'admin/security', null],
        'audit'          => ['Audit log', 'admin/audit', 'audit.view'],
        'system'         => ['System', 'admin/system', 'jobs.view'],
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
        [$title, $view, $permission] = self::PAGES[$page];
        if ($permission !== null) {
            $this->authorize($req, $permission);
        }
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
