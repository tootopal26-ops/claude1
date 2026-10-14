<?php
/**
 * Route table. Admin routes are appended in app/routes_admin.php.
 *
 * Middleware cheat-sheet:
 *   publicJsonPost  — same-origin + JSON + X-Requested-With (checkout)
 *   beaconPost      — same-origin + JSON (sendBeacon cannot add headers)
 */

declare(strict_types=1);

use App\Controllers\{CheckoutController, CronController, InstallController, OrderController, PayController, SiteController, TrackController, WebhookController};
use App\Core\Middleware;
use App\Core\Router;

return static function (Router $r): void {
    $json = [[Middleware::class, 'publicJsonPost']];
    $beacon = [[Middleware::class, 'beaconPost']];

    // ── storefront ──
    $r->get('/', [SiteController::class, 'home']);
    $r->get('/theme.css', [SiteController::class, 'themeCss']);
    $r->get('/manifest.webmanifest', [SiteController::class, 'manifest']);
    $r->get('/robots.txt', [SiteController::class, 'robots']);
    $r->get('/sitemap.xml', [SiteController::class, 'sitemap']);
    $r->get('/health', [SiteController::class, 'health']);

    // ── checkout / payment ──
    $r->post('/api/checkout', [CheckoutController::class, 'create'], $json);
    $r->get('/pay/{ref:[a-f0-9]{32}}', [PayController::class, 'show']);
    $r->get('/pay/{ref:[a-f0-9]{32}}/qr.png', [PayController::class, 'qr']);
    $r->get('/api/pay/{ref:[a-f0-9]{32}}/status', [PayController::class, 'status']);

    // ── customer order tracking ──
    $r->get('/order/{token:[a-f0-9]{32}}', [OrderController::class, 'show']);
    $r->get('/api/order/{token:[a-f0-9]{32}}', [OrderController::class, 'status']);
    $r->post('/api/orders/lookup', [OrderController::class, 'lookup'], $json);

    // ── analytics beacons ──
    $r->post('/api/track/hit', [TrackController::class, 'hit'], $beacon);
    $r->post('/api/track/qualify', [TrackController::class, 'qualify'], $beacon);
    $r->post('/api/track/ping', [TrackController::class, 'ping'], $beacon);

    // ── web installer (404 once installed) ──
    $r->get('/install', [InstallController::class, 'show']);
    $r->post('/install/unlock', [InstallController::class, 'unlock']);
    $r->post('/install/test-db', [InstallController::class, 'testDb']);
    $r->post('/install/run', [InstallController::class, 'run']);

    // ── machine endpoints ──
    $r->post('/webhooks/payments/{id:\d+}', [WebhookController::class, 'payments']);
    $r->add('GET', '/cron/run', [CronController::class, 'run']);
    $r->add('POST', '/cron/run', [CronController::class, 'run']);

    if (is_file(SMB_ROOT . '/app/routes_admin.php')) {
        (require SMB_ROOT . '/app/routes_admin.php')($r);
    }
};
