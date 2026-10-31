<?php
/**
 * Admin routes.
 *
 *   /admin/login (+POST)  public, protected by a signed pre-login CSRF token and rate limits
 *   /admin/{page}         HTML shells, redirect to login when signed out        (adminPage)
 *   /admin/api/*          JSON, signed-in + CSRF header on every unsafe method  (adminApi)
 */

declare(strict_types=1);

use App\Controllers\Admin\{AccountController, AdsController, AiController, AlertsController, AuthController, DashboardController, FinanceController, IntegrationsController, JobsController, OrdersController, PageController, PaymentChecksController, PricingController, RecoveryController, ReportsController, ServicesController, SettingsController, SupportController, TagsController};
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

    // ── finance: the profit statement, an order's cost lines, expenses and chargebacks, reconciliation, the fee model ──
    $r->get('/admin/api/finance', [FinanceController::class, 'overview'], $api);
    $r->get('/admin/api/finance/reconciliation', [FinanceController::class, 'reconciliation'], $api);
    $r->post('/admin/api/finance/reconciliation/fix', [FinanceController::class, 'fix'], $api);
    $r->get('/admin/api/finance/expenses', [FinanceController::class, 'expenses'], $api);
    $r->post('/admin/api/finance/expenses', [FinanceController::class, 'addExpense'], $api);
    $r->post("/admin/api/finance/expenses/{$id}/void", [FinanceController::class, 'voidExpense'], $api);
    $r->get('/admin/api/finance/settings', [FinanceController::class, 'settings'], $api);
    $r->put('/admin/api/finance/settings', [FinanceController::class, 'saveSettings'], $api);
    $r->get("/admin/api/finance/orders/{$id}", [FinanceController::class, 'order'], $api);
    $r->post("/admin/api/finance/orders/{$id}/costs", [FinanceController::class, 'recordCost'], $api);
    $r->post("/admin/api/finance/costs/{$id}/void", [FinanceController::class, 'voidCost'], $api);

    // ── ads intelligence: campaign figures, actual spend, the attribution window ──
    $r->get('/admin/api/ads', [AdsController::class, 'overview'], $api);
    $r->get('/admin/api/ads/spend', [AdsController::class, 'spend'], $api);
    $r->post('/admin/api/ads/spend', [AdsController::class, 'addSpend'], $api);
    $r->post('/admin/api/ads/spend/import', [AdsController::class, 'importSpend'], $api);
    $r->post("/admin/api/ads/spend/{$id}/void", [AdsController::class, 'voidSpend'], $api);
    $r->get('/admin/api/ads/settings', [AdsController::class, 'settings'], $api);
    $r->put('/admin/api/ads/settings', [AdsController::class, 'saveSettings'], $api);
    $r->post('/admin/api/ads/attribution/backfill', [AdsController::class, 'backfill'], $api);

    // ── business watchdog: the alerts, what each check last saw, thresholds, e-mail ──
    $r->get('/admin/api/alerts', [AlertsController::class, 'list'], $api);
    $r->get('/admin/api/alerts/checks', [AlertsController::class, 'checks'], $api);
    $r->get('/admin/api/alerts/settings', [AlertsController::class, 'settings'], $api);
    $r->put('/admin/api/alerts/settings', [AlertsController::class, 'saveSettings'], $api);
    $r->put('/admin/api/alerts/mail', [AlertsController::class, 'saveMail'], $api);
    $r->post('/admin/api/alerts/mail/test', [AlertsController::class, 'testMail'], $api);
    $r->post("/admin/api/alerts/{$id}/acknowledge", [AlertsController::class, 'acknowledge'], $api);
    $r->post("/admin/api/alerts/{$id}/resolve", [AlertsController::class, 'resolve'], $api);

    // ── daily reports: the history, one report, (re)generate a day ──
    $r->get('/admin/api/reports', [ReportsController::class, 'list'], $api);
    $r->get('/admin/api/reports/{date:\d{4}-\d{2}-\d{2}}', [ReportsController::class, 'show'], $api);
    $r->post('/admin/api/reports/{date:\d{4}-\d{2}-\d{2}}/generate', [ReportsController::class, 'generate'], $api);
    $r->post('/admin/api/reports/{date:\d{4}-\d{2}-\d{2}}/ai', [ReportsController::class, 'generateAi'], $api);

    // ── AI provider: settings, test, usage ──
    $r->get('/admin/api/ai/settings', [AiController::class, 'settings'], $api);
    $r->put('/admin/api/ai/settings', [AiController::class, 'saveSettings'], $api);
    $r->post('/admin/api/ai/test', [AiController::class, 'test'], $api);
    $r->get('/admin/api/ai/usage', [AiController::class, 'usage'], $api);

    // ── adaptive pricing: experiments, the simulator, profit guard, learning history ──
    $r->get('/admin/api/pricing/settings', [PricingController::class, 'settings'], $api);
    $r->put('/admin/api/pricing/settings', [PricingController::class, 'saveSettings'], $api);
    $r->get('/admin/api/pricing/services', [PricingController::class, 'services'], $api);
    $r->put("/admin/api/pricing/services/{$id}", [PricingController::class, 'saveService'], $api);
    $r->post("/admin/api/pricing/simulate/{$id}", [PricingController::class, 'simulate'], $api);
    $r->get('/admin/api/pricing/experiments', [PricingController::class, 'experiments'], $api);
    $r->post('/admin/api/pricing/experiments', [PricingController::class, 'propose'], $api);
    $r->get("/admin/api/pricing/experiments/{$id}", [PricingController::class, 'experiment'], $api);
    $r->post("/admin/api/pricing/experiments/{$id}/start", [PricingController::class, 'start'], $api);
    $r->post("/admin/api/pricing/experiments/{$id}/go-live", [PricingController::class, 'goLive'], $api);
    $r->post("/admin/api/pricing/experiments/{$id}/pause", [PricingController::class, 'pause'], $api);
    $r->post("/admin/api/pricing/experiments/{$id}/resume", [PricingController::class, 'resume'], $api);
    $r->post("/admin/api/pricing/experiments/{$id}/rollback", [PricingController::class, 'rollback'], $api);
    $r->post("/admin/api/pricing/experiments/{$id}/apply", [PricingController::class, 'applyWinner'], $api);
    $r->post("/admin/api/pricing/experiments/{$id}/cancel", [PricingController::class, 'cancel'], $api);
    $r->post("/admin/api/pricing/experiments/{$id}/evaluate", [PricingController::class, 'evaluateNow'], $api);
    $r->post("/admin/api/pricing/experiments/{$id}/ai-insight", [PricingController::class, 'aiInsight'], $api);
    $r->get('/admin/api/pricing/profit-guard', [PricingController::class, 'profitGuard'], $api);
    $r->post('/admin/api/pricing/global-pause', [PricingController::class, 'globalPause'], $api);
    $r->get('/admin/api/pricing/learning-history', [PricingController::class, 'learningHistory'], $api);

    // ── support assistant: monitoring, settings ──
    $r->get('/admin/api/support/overview', [SupportController::class, 'overview'], $api);
    $r->get('/admin/api/support/conversations', [SupportController::class, 'conversations'], $api);
    $r->get("/admin/api/support/conversations/{$id}", [SupportController::class, 'conversation'], $api);
    $r->put('/admin/api/support/settings', [SupportController::class, 'saveSettings'], $api);

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

    // ── sales target & ad budget intelligence ──
    $r->put('/admin/api/sales-target', [SettingsController::class, 'saveSalesTarget'], $api);
    $r->post('/admin/api/sales-target/revoke', [SettingsController::class, 'revokeSalesTarget'], $api);
    $r->get('/admin/api/sales-target/history', [SettingsController::class, 'salesTargetHistory'], $api);
    $r->put('/admin/api/ad-budget', [SettingsController::class, 'saveAdBudget'], $api);
    $r->post('/admin/api/ad-budget/reset', [SettingsController::class, 'resetAdBudget'], $api);
    $r->post('/admin/api/ad-budget/revoke-override', [SettingsController::class, 'revokeAdBudgetOverride'], $api);
    $r->get('/admin/api/ad-budget/history', [SettingsController::class, 'adBudgetHistory'], $api);
    $r->post('/admin/api/ad-budget/forecast', [SettingsController::class, 'forecastAdBudget'], $api);

    // ── appearance & themes ──
    $r->get('/admin/api/appearance', [SettingsController::class, 'appearance'], $api);
    $r->put('/admin/api/appearance', [SettingsController::class, 'saveAppearance'], $api);
    $r->post('/admin/api/appearance/reset', [SettingsController::class, 'resetAppearance'], $api);
    $r->post('/admin/api/appearance/revert', [SettingsController::class, 'revertAppearance'], $api);

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
