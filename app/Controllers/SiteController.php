<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Catalog;
use App\Services\Settings;

final class SiteController
{
    public function home(Request $req, array $p): Response
    {
        $site = Settings::site();
        if ($site['maintenance']) {
            $html = View::render('site/maintenance', ['site' => $site, 'title' => $site['name'], 'page' => 'maintenance', 'noindex' => true, 'shop' => false], 'layouts/site');
            return Response::html($html, 503)->header('Retry-After', '900')->header('Cache-Control', 'no-store');
        }
        $platforms = Catalog::storefront();
        $items = [];
        foreach ($platforms as $pl) {
            foreach ($pl['sections'] as $sec) {
                foreach ($sec['services'] as $s) {
                    $items[] = [
                        '@type' => 'ListItem', 'position' => count($items) + 1,
                        'item' => [
                            '@type' => 'Product', 'name' => $s['title'], 'description' => $s['subtitle'] ?: $s['title'],
                            'brand' => ['@type' => 'Brand', 'name' => $site['name']],
                            'offers' => ['@type' => 'Offer', 'price' => number_format($s['price_paise'] / 100, 2, '.', ''), 'priceCurrency' => 'INR',
                                'availability' => 'https://schema.org/InStock', 'url' => absolute_url('/') . '#services'],
                        ],
                    ];
                }
            }
        }
        $jsonLd = ['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'WebSite', 'name' => $site['name'], 'url' => absolute_url('/')],
            ['@type' => 'Organization', 'name' => $site['name'], 'url' => absolute_url('/'), 'logo' => absolute_url(strtok($site['logo_url'], '?'))],
            ['@type' => 'ItemList', 'name' => 'Services', 'itemListElement' => $items],
        ]];
        $html = View::render('site/home', ['site' => $site, 'platforms' => $platforms]);
        $page = View::render('layouts/site', [
            'content' => $html, 'site' => $site, 'title' => $site['seo_title'], 'page' => 'home', 'jsonLd' => $jsonLd,
            'scripts' => ['site', 'track'], 'canonical' => absolute_url('/'),
            'ogImage' => $site['has_custom_logo'] && !str_ends_with(strtok($site['logo_url'], '?'), '.svg') ? $site['logo_url'] : asset('img/og-default.png'),
        ]);
        return $this->cached($req, $page);
    }

    /** Conditional GET: skip the body when the browser already has this exact page. */
    private function cached(Request $req, string $html): Response
    {
        $etag = '"' . md5($html) . '"';
        if ($req->header('if-none-match') === $etag) {
            return (new Response(304))->header('ETag', $etag)->header('Cache-Control', 'no-cache');
        }
        return Response::html($html)->header('ETag', $etag)->header('Cache-Control', 'no-cache');
    }

    /** Admin-chosen accent colours as CSS variables. */
    public function themeCss(Request $req, array $p): Response
    {
        $s = Settings::site();
        $css = ":root{--accent-from:{$s['accent_from']};--accent-to:{$s['accent_to']}}\n";
        return Response::text($css, 200, 'text/css')->header('Cache-Control', 'public, max-age=86400')->header('X-Content-Type-Options', 'nosniff');
    }

    /** Web app manifest (installable shortcut): name and description follow the admin's website settings. */
    public function manifest(Request $req, array $p): Response
    {
        $s = Settings::site();
        $png = static fn(string $file, string $size, string $purpose = 'any'): array => ['src' => asset('img/' . $file), 'sizes' => $size, 'type' => 'image/png', 'purpose' => $purpose];
        $json = [
            'name' => $s['name'], 'short_name' => mb_substr($s['name'], 0, 12), 'description' => $s['tagline'],
            'start_url' => url('/'), 'scope' => url('/'), 'display' => 'standalone', 'background_color' => '#ffffff', 'theme_color' => '#ffffff',
            'icons' => [$png('icon-192.png', '192x192'), $png('icon-512.png', '512x512'), $png('icon-maskable-512.png', '512x512', 'maskable')],
        ];
        return Response::json($json)->header('Content-Type', 'application/manifest+json; charset=utf-8')->header('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $req, array $p): Response
    {
        $body = "User-agent: *\nDisallow: /admin\nDisallow: /pay/\nDisallow: /order/\nDisallow: /api/\nDisallow: /webhooks/\nDisallow: /cron/\nDisallow: /install\n\nSitemap: " . absolute_url('/sitemap.xml') . "\n";
        return Response::text($body)->header('Cache-Control', 'public, max-age=3600');
    }

    public function sitemap(Request $req, array $p): Response
    {
        $last = (string) Db::val('SELECT MAX(updated_at) FROM services');
        $mod = $last !== '' ? gmdate('Y-m-d', (int) strtotime($last . ' UTC')) : gmdate('Y-m-d');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . e(absolute_url('/')) . '</loc><lastmod>' . $mod . '</lastmod><changefreq>daily</changefreq><priority>1.0</priority></url></urlset>' . "\n";
        return Response::text($xml, 200, 'application/xml')->header('Cache-Control', 'public, max-age=3600');
    }

    /** Liveness probe: 200 when PHP and the database answer. No internals are disclosed. */
    public function health(Request $req, array $p): Response
    {
        try {
            Db::val('SELECT 1');
            return Response::json(['status' => 'ok']);
        } catch (\Throwable) {
            return Response::json(['status' => 'down'], 503);
        }
    }
}
