<?php
declare(strict_types=1);

use App\Controllers\Admin\AiController;
use App\Controllers\Admin\SupportController;
use App\Core\Db;
use App\Core\HttpException;
use App\Services\AiService;
use App\Services\SupportAssistant;

/**
 * Who may see and change the AI provider and the support assistant. `ai.configure` decides whether any data leaves the server
 * for a third party at all, so it is **owner only** — not even `manager`. Reading support conversations needs `support.view`
 * (owner, manager, support — the roles that already help customers); switching the assistant on/off and its retention need
 * `support.configure` (owner, manager). A refused call changes nothing and writes no audit row.
 */
T::test('AI settings: owner only — manager, support and analyst are all refused, and a refused call changes nothing', function () {
    ai_reset();
    $ctl = new AiController();
    foreach (['owner'] as $role) {
        T::true(la_json($ctl->settings(la_req('GET', '/admin/api/ai/settings', $role), []))['ok']);
    }
    foreach (['manager', 'support', 'analyst'] as $role) {
        $e = T::throws(HttpException::class, fn() => $ctl->settings(la_req('GET', '/admin/api/ai/settings', $role), []), 403);
        T::same('forbidden', $e->errorCode, "$role reads settings");
    }
    $before = [AiService::settings(), (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'ai.%'")];
    foreach (['manager', 'support', 'analyst'] as $role) {
        $save = static fn(): \App\Core\Response => $ctl->saveSettings(la_req('PUT', '/admin/api/ai/settings', $role, ['enabled' => true, 'provider' => 'anthropic', 'model' => 'x', 'api_key' => 'k']), []);
        T::throws(HttpException::class, $save, 403);
        T::throws(HttpException::class, fn() => $ctl->test(la_req('POST', '/admin/api/ai/test', $role), []), 403);
        T::throws(HttpException::class, fn() => $ctl->usage(la_req('GET', '/admin/api/ai/usage', $role), []), 403);
    }
    T::same($before, [AiService::settings(), (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'ai.%'")], 'none of the refused calls changed or recorded anything');

    $s = la_json($ctl->saveSettings(la_req('PUT', '/admin/api/ai/settings', 'owner', ['enabled' => true, 'provider' => 'anthropic', 'model' => 'x', 'api_key' => 'k']), []));
    T::true($s['enabled']);
    T::true((int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'ai.settings_changed'") >= 1);
});

T::test('Support Assistant: everyone who helps customers reads conversations; only owner and manager change the settings', function () {
    ai_reset();
    sa_start();
    $ctl = new SupportController();
    foreach (['owner', 'manager', 'support'] as $role) {
        T::true(la_json($ctl->overview(la_req('GET', '/admin/api/support/overview', $role), []))['ok'], "$role reads overview");
        T::true(la_json($ctl->conversations(la_req('GET', '/admin/api/support/conversations', $role), []))['ok'], "$role reads conversations");
    }
    $e = T::throws(HttpException::class, fn() => $ctl->overview(la_req('GET', '/admin/api/support/overview', 'analyst'), []), 403);
    T::same('forbidden', $e->errorCode);
    $id = (int) Db::val('SELECT id FROM support_conversations ORDER BY id DESC LIMIT 1');
    T::true(la_json($ctl->conversation(la_req('GET', "/admin/api/support/conversations/{$id}", 'support'), ['id' => (string) $id]))['ok']);

    $write = static fn(string $role): \App\Core\Response => $ctl->saveSettings(la_req('PUT', '/admin/api/support/settings', $role, ['retention_days' => 10]), []);
    foreach (['support', 'analyst'] as $role) {
        $e = T::throws(HttpException::class, fn() => $write($role), 403);
        T::same('forbidden', $e->errorCode, "$role is refused the settings write");
    }
    T::same(30, (int) \App\Services\Settings::get('support.retention_days', 30), 'unchanged by the refused calls');

    foreach (['manager', 'owner'] as $role) {
        T::same(10, la_json($write($role))['retention_days'], "$role changes it");
    }
});
