<?php
/**
 * Admin routes.
 *
 *   /admin/login (+POST)  public, protected by a signed pre-login CSRF token and rate limits
 *   /admin/{page}         HTML shells, redirect to login when signed out        (adminPage)
 *   /admin/api/*          JSON, signed-in + CSRF header on every unsafe method  (adminApi)
 */

declare(strict_types=1);

use App\Controllers\Admin\{AccountController, AuthController, DashboardController, IntegrationsController, JobsController, OrdersController, PageController, PaymentChecksController, RecoveryController, ServicesController, SettingsController, TagsController};
use App\Core\Middleware;
use App\Core\Router;

return static function (Router $r): void {
    $page = [[Middleware::class, 'adminPage']];
    $api = [[Middleware::class, 'adminApi']];
    $id = '{id:\d+}';

    // ── sign in / out ──
    $r->get('/admin/login', [AuthController::class, 'page'], [[Middleware::class, 'noIndex']]);
    $r->post('/admin/login', [AuthController::class, 'login'], [[Middleware::class, 'noIndex']]);
    $r->post('/admin/logout', [AuthController::class, 'logout'], $api);

    // ── pages ──
    $r->get('/admin', [PageController::class, 'index']);
    $r->get('/admin/{page:[a-z][a-z\-]{2,28}}', [PageController::class, 'show'], $page);

    // ── dashboard ──
    $r->get('/admin/api/dashboard', [DashboardController::class, 'data'], $api);
    $r->get('/admin/api/status', [DashboardController::class, 'status'], $api);

    // ── orders & payments ──
    $r->get('/admin/api/orders', [OrdersController::class, 'list'], $api);
    $r->get('/admin/api/orders/approvable', [OrdersController::class, 'approvable'], $api);
    $r->post('/admin/api/orders/approve-all', [OrdersController::class, 'approveAll'], $api);
    $r->get("/admin/api/orders/{$id}", [OrdersController::class, 'show'], $api);
    $r->post("/admin/api/orders/{$id}/action", [OrdersController::class, 'act'], $api);
    $r->get('/admin/api/payments', [OrdersController::class, 'payments'], $api);

    // ── order recovery: the queue, safe retries, reconcile actions, the retry policy ──
    $r->get('/admin/api/recovery', [RecoveryController::class, 'queue'], $api);
    $r->get('/admin/api/recovery/settings', [RecoveryController::class, 'settings'], $api);
    $r->put('/admin/api/recovery/settings', [RecoveryController::class, 'saveSettings'], $api);
    $r->post("/admin/api/recovery/{$id}/action", [RecoveryController::class, 'act'], $api);

    // ── scheduled jobs: health and "run now" ──
    $r->get('/admin/api/jobs', [JobsController::class, 'list'], $api);
    $r->post('/admin/api/jobs/{job:[a-z][a-z0-9\-]{2,24}}/run', [JobsController::class, 'run'], $api);

    // ── payment checks: monitor, flags, thresholds, re-check ──
    $r->get('/admin/api/payment-checks/summary', [PaymentChecksController::class, 'summary'], $api);
    $r->get('/admin/api/payment-checks', [PaymentChecksController::class, 'monitor'], $api);
    $r->get('/admin/api/payment-checks/settings', [PaymentChecksController::class, 'settings'], $api);
    $r->put('/admin/api/payment-checks/settings', [PaymentChecksController::class, 'saveSettings'], $api);
    $r->get('/admin/api/payment-flags', [PaymentChecksController::class, 'flags'], $api);
    $r->post("/admin/api/payment-flags/{$id}/review", [PaymentChecksController::class, 'review'], $api);
    $r->post("/admin/api/payments/{$id}/recheck", [PaymentChecksController::class, 'recheck'], $api);

    // ── services & platforms ──
    $r->get('/admin/api/services', [ServicesController::class, 'list'], $api);
    $r->get('/admin/api/services/meta', [ServicesController::class, 'meta'], $api);
    $r->post('/admin/api/services/icon', [ServicesController::class, 'icon'], $api);
    $r->post('/admin/api/services', [ServicesController::class, 'create'], $api);
    $r->get("/admin/api/services/{$id}", [ServicesController::class, 'show'], $api);
    $r->put("/admin/api/services/{$id}", [ServicesController::class, 'update'], $api);
    $r->delete("/admin/api/services/{$id}", [ServicesController::class, 'delete'], $api);
    $r->patch("/admin/api/platforms/{$id}", [ServicesController::class, 'platform'], $api);
    $r->post("/admin/api/platforms/{$id}/logo", [ServicesController::class, 'platformLogo'], $api);
    $r->delete("/admin/api/platforms/{$id}/logo", [ServicesController::class, 'platformLogoReset'], $api);

    // ── service tags & badges ──
    $r->get('/admin/api/tags', [TagsController::class, 'list'], $api);
    $r->post('/admin/api/tags', [TagsController::class, 'create'], $api);
    $r->put("/admin/api/tags/{$id}", [TagsController::class, 'update'], $api);
    $r->delete("/admin/api/tags/{$id}", [TagsController::class, 'delete'], $api);

    // ── website / advertising ──
    $r->get('/admin/api/website', [SettingsController::class, 'website'], $api);
    $r->put('/admin/api/website', [SettingsController::class, 'saveWebsite'], $api);
    $r->post('/admin/api/branding/{kind:site_logo|favicon}', [SettingsController::class, 'uploadBranding'], $api);
    $r->delete('/admin/api/branding/{kind:site_logo|favicon}', [SettingsController::class, 'resetBranding'], $api);
    $r->get('/admin/api/advertising', [SettingsController::class, 'advertising'], $api);
    $r->put('/admin/api/advertising', [SettingsController::class, 'saveAdvertising'], $api);

    // ── integrations ──
    $r->get('/admin/api/gateway', [IntegrationsController::class, 'gateway'], $api);
    $r->put("/admin/api/gateway/{$id}", [IntegrationsController::class, 'saveGateway'], $api);
    $r->post("/admin/api/gateway/{$id}/test", [IntegrationsController::class, 'testGateway'], $api);
    $r->post("/admin/api/gateway/{$id}/qr-check", [IntegrationsController::class, 'qrCheckGateway'], $api);
    $r->get('/admin/api/smm', [IntegrationsController::class, 'providers'], $api);
    $r->post('/admin/api/smm', [IntegrationsController::class, 'createProvider'], $api);
    $r->put("/admin/api/smm/{$id}", [IntegrationsController::class, 'updateProvider'], $api);
    $r->delete("/admin/api/smm/{$id}", [IntegrationsController::class, 'deleteProvider'], $api);
    $r->post("/admin/api/smm/{$id}/test", [IntegrationsController::class, 'testProvider'], $api);
    $r->get("/admin/api/smm/{$id}/services", [IntegrationsController::class, 'providerServices'], $api);

    // ── account, audit, system ──
    $r->post('/admin/api/account/password', [AccountController::class, 'changePassword'], $api);
    $r->get('/admin/api/account/sessions', [AccountController::class, 'sessions'], $api);
    $r->delete("/admin/api/account/sessions/{$id}", [AccountController::class, 'revokeSession'], $api);
    $r->post('/admin/api/account/sessions/revoke-others', [AccountController::class, 'revokeOthers'], $api);
    $r->get('/admin/api/audit', [AccountController::class, 'audit'], $api);
    $r->get('/admin/api/system', [AccountController::class, 'system'], $api);
    $r->post('/admin/api/system/cron', [AccountController::class, 'runCron'], $api);
    $r->post('/admin/api/system/migrate', [AccountController::class, 'migrate'], $api);
};
