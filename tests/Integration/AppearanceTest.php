<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Request;
use App\Services\AdminSettings;
use App\Services\Settings;
use App\Support\Themes;

/** Appearance & Themes: persistence, validation, reset and one-level rollback. */

const AP_ADMIN = ['id' => 1, 'username' => 'tester', 'email' => 't@example.test', 'role' => 'owner'];
function ap_req(): Request { return Request::fake('PUT', '/admin/api/appearance'); }

T::test('a fresh install defaults to Rose Glass, standard density, and the documented default accent', function () {
    T::freshDb();
    $a = AdminSettings::appearance();
    T::same('rose', $a['theme']);
    T::same('standard', $a['density']);
    T::same(strtolower(Themes::defaultAccent('rose')[0]), strtolower($a['accent_from']));
    T::false($a['can_revert']);
    T::false($a['can_reset']);
    T::same(4, count($a['themes']));
});

T::test('saving a new theme switches the accent to that theme\'s own default, persists, and is audited', function () {
    T::freshDb();
    $saved = AdminSettings::saveAppearance(['theme' => 'obsidian', 'density' => 'premium'], ap_req(), AP_ADMIN);
    T::same('obsidian', $saved['theme']);
    T::same('premium', $saved['density']);
    T::same(strtolower(Themes::defaultAccent('obsidian')[0]), strtolower($saved['accent_from']));
    T::true($saved['can_revert']);
    T::true($saved['can_reset']);

    // persisted — a fresh read agrees
    T::same('obsidian', Settings::site()['theme']);
    T::same('premium', Settings::site()['theme_density']);
    T::same('dark', Settings::site()['color_scheme']);

    $log = \App\Core\Db::one("SELECT * FROM audit_logs WHERE action = 'settings.appearance' ORDER BY id DESC LIMIT 1");
    T::true($log !== null, 'the change is audited');
    T::contains('obsidian', (string) $log['meta_json']);
});

T::test('a custom accent survives a theme switch only when it is explicitly sent again; otherwise the new theme\'s own default applies', function () {
    T::freshDb();
    AdminSettings::saveAppearance(['theme' => 'rose', 'accent_from' => '#123456', 'accent_to' => '#654321'], ap_req(), AP_ADMIN);
    T::same('#123456', Settings::site()['accent_from']);

    // switching theme WITHOUT resending accent falls back to that theme's own default (matches the admin UI's own pre-fill)
    $after = AdminSettings::saveAppearance(['theme' => 'emerald'], ap_req(), AP_ADMIN);
    T::same(strtolower(Themes::defaultAccent('emerald')[0]), strtolower($after['accent_from']));

    // switching theme AND resending a custom accent keeps the custom one
    $custom = AdminSettings::saveAppearance(['theme' => 'aurora', 'accent_from' => '#0a0a0a', 'accent_to' => '#1a1a1a'], ap_req(), AP_ADMIN);
    T::same('#0a0a0a', $custom['accent_from']);
});

T::test('validation: an unknown theme/density is refused, and an accent that fails the button-contrast rule is refused', function () {
    T::freshDb();
    $e1 = T::throws(HttpException::class, fn() => AdminSettings::saveAppearance(['theme' => 'neon-pink'], ap_req(), AP_ADMIN), 422);
    T::true(isset($e1->extra['fields']['theme']));
    $e2 = T::throws(HttpException::class, fn() => AdminSettings::saveAppearance(['density' => 'ultra'], ap_req(), AP_ADMIN), 422);
    T::true(isset($e2->extra['fields']['density']));
    $e3 = T::throws(HttpException::class, fn() => AdminSettings::saveAppearance(['accent_from' => '#ffffff'], ap_req(), AP_ADMIN), 422);
    T::true(isset($e3->extra['fields']['accent_from']), 'white fails the 4.5:1 white-button-text contrast rule');
    T::same('rose', Settings::site()['theme'], 'none of the rejected attempts changed anything');
});

T::test('saving the exact same configuration again is a no-op (no audit noise, no previous-snapshot overwrite)', function () {
    T::freshDb();
    AdminSettings::saveAppearance(['theme' => 'obsidian', 'density' => 'compact'], ap_req(), AP_ADMIN);
    $before = \App\Core\Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.appearance'");
    AdminSettings::saveAppearance(['theme' => 'obsidian', 'density' => 'compact'], ap_req(), AP_ADMIN);
    $after = \App\Core\Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.appearance'");
    T::same($before, $after);
});

T::test('reset restores the exact built-in defaults regardless of how far the admin wandered', function () {
    T::freshDb();
    AdminSettings::saveAppearance(['theme' => 'obsidian', 'density' => 'premium', 'accent_from' => '#111111', 'accent_to' => '#222222'], ap_req(), AP_ADMIN);
    $reset = AdminSettings::resetAppearance(ap_req(), AP_ADMIN);
    T::same(Themes::DEFAULT, $reset['theme']);
    T::same(Themes::DEFAULT_DENSITY, $reset['density']);
    T::same(strtolower(Themes::defaultAccent(Themes::DEFAULT)[0]), strtolower($reset['accent_from']));
    T::false($reset['can_reset']);
});

T::test('revert restores exactly what was live before the last change, and is itself reversible', function () {
    T::freshDb();
    AdminSettings::saveAppearance(['theme' => 'rose', 'density' => 'standard'], ap_req(), AP_ADMIN);
    AdminSettings::saveAppearance(['theme' => 'obsidian', 'density' => 'premium'], ap_req(), AP_ADMIN);
    $reverted = AdminSettings::revertAppearance(ap_req(), AP_ADMIN);
    T::same('rose', $reverted['theme']);
    T::same('standard', $reverted['density']);

    // revert is itself reversible: reverting again goes back to obsidian/premium
    $again = AdminSettings::revertAppearance(ap_req(), AP_ADMIN);
    T::same('obsidian', $again['theme']);
    T::same('premium', $again['density']);
});

T::test('reverting with no previous change is refused cleanly, never crashes', function () {
    T::freshDb();
    T::throws(HttpException::class, fn() => AdminSettings::revertAppearance(ap_req(), AP_ADMIN), 409);
});

T::test('the public /theme.css builder reflects the saved theme, density and accent exactly', function () {
    T::freshDb();
    AdminSettings::saveAppearance(['theme' => 'emerald', 'density' => 'compact', 'accent_from' => '#0a0a0a', 'accent_to' => '#1a1a1a'], ap_req(), AP_ADMIN);
    $site = Settings::site();
    $css = Themes::css($site['theme'], $site['theme_density'], $site['accent_from'], $site['accent_to']);
    T::contains('--accent-from:#0a0a0a', $css);
    T::contains('--accent-to:#1a1a1a', $css);
    T::contains('color-scheme:light', $css);
    T::contains('--glass-blur:12px', $css); // compact density
});
