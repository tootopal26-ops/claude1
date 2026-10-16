<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AdminServices;
use App\Services\AdminSettings;
use App\Services\Ads;
use App\Services\Settings;
use App\Support\Tz;

const AC_ADMIN = ['id' => 1, 'username' => 'tester', 'email' => 't@example.test', 'role' => 'owner'];
function ac_req(): Request { return Request::fake('PUT', '/admin/api/x'); }
function ac_base(array $over = []): array
{
    return $over + ['platform_id' => (int) Db::val("SELECT id FROM platforms WHERE slug = 'instagram'"), 'type' => 'followers', 'target_kind' => 'profile', 'title' => 'QA Pack',
        'code' => 'qa-pack', 'price' => '12.50', 'quantity' => '500', 'min_quantity' => '1', 'max_quantity' => '1000000', 'mode' => 'MANUAL', 'status' => 'active'];
}
function ac_fields(callable $fn): array
{
    try {
        $fn();
    } catch (HttpException $e) {
        return $e->extra['fields'] ?? [];
    }
    return [];
}

T::test('admin services: create stores integer paise, writes pricing history + audit, and shows on the storefront catalogue', function () {
    T::freshDb();
    $s = AdminServices::save(null, ac_base(), ac_req(), AC_ADMIN);
    T::same(1250, $s['price_paise']);
    T::same('12.50', $s['price']);
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM service_pricing WHERE service_id = ?', [$s['id']]));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'service.created'"));
    $titles = array_column(array_merge(...array_column(array_merge(...array_column(\App\Services\Catalog::storefront(), 'sections')), 'services')), 'title');
    T::true(in_array('QA Pack', $titles, true));
});

T::test('admin services: validation catches every bad field (price, quantity ranges, duplicate code, AUTO without SMM id, target kind vs platform)', function () {
    T::freshDb();
    $cases = [
        'price' => ['abc', '5.999', '0', '-1', '100001', '', '1e3'],
        'quantity' => ['0', '-5', 'x', '3000000000'],
        'code' => ['', 'A', 'UPPER', 'has space', '-lead', 'a/b', str_repeat('x', 70), 'ig-followers-50'],
        'title' => ['', str_repeat('t', 121)],
        'mode' => ['AUTOMATIC', ''],
        'status' => ['deleted', ''],
        'target_kind' => ['video'],           // not valid on Instagram
        'type' => ['nonsense'],
    ];
    foreach ($cases as $field => $values) {
        foreach ($values as $v) {
            $f = ac_fields(fn() => AdminServices::save(null, ac_base([$field => $v]), ac_req(), AC_ADMIN));
            T::true(isset($f[$field]), "{$field} = " . json_encode($v) . ' must be rejected; got ' . json_encode(array_keys($f)));
        }
    }
    $f = ac_fields(fn() => AdminServices::save(null, ac_base(['mode' => 'AUTO']), ac_req(), AC_ADMIN));
    T::true(isset($f['smm_service_id']), 'AUTO needs an SMM service ID');
    $f = ac_fields(fn() => AdminServices::save(null, ac_base(['min_quantity' => '10', 'max_quantity' => '5']), ac_req(), AC_ADMIN));
    T::true(isset($f['max_quantity']));
    $f = ac_fields(fn() => AdminServices::save(null, ac_base(['quantity' => '2000', 'max_quantity' => '1000']), ac_req(), AC_ADMIN));
    T::true(isset($f['quantity']), 'pack quantity must sit inside min/max');
    T::same(14, (int) Db::val('SELECT COUNT(*) FROM services'), 'nothing was created by the rejected saves');
});

T::test('admin services: JSON floats and numeric strings both become exact paise (no 1.08 → 107 drift)', function () {
    T::freshDb();
    $a = AdminServices::save(null, ac_base(['code' => 'qa-float', 'price' => 1.08]), ac_req(), AC_ADMIN);
    T::same(108, $a['price_paise']);
    $b = AdminServices::save(null, ac_base(['code' => 'qa-float2', 'price' => 19.99, 'smm_cost' => 0.07]), ac_req(), AC_ADMIN);
    T::same(1999, $b['price_paise']);
    T::same(7, $b['smm_cost_paise']);
});

T::test('admin services: partial update keeps untouched fields; a no-op save writes no history and no audit; price change is audited', function () {
    T::freshDb();
    $id = (int) Db::val("SELECT id FROM services WHERE code = 'ig-likes-100'");
    $before = (int) Db::val('SELECT COUNT(*) FROM audit_logs');
    AdminServices::save($id, [], ac_req(), AC_ADMIN);
    T::same($before, (int) Db::val('SELECT COUNT(*) FROM audit_logs'), 'no-op save is silent');
    $s = AdminServices::save($id, ['price' => '7.50'], ac_req(), AC_ADMIN);
    T::same(750, $s['price_paise']);
    T::same('100 Likes', $s['title'], 'title untouched');
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'service.price_changed'"));
    T::same(2, (int) Db::val('SELECT COUNT(*) FROM service_pricing WHERE service_id = ?', [$id]), 'initial + changed');
});

T::test('admin services: manual fulfilment cost — empty is "not stated", 0 is a stated zero, bad input is refused, an untouched save keeps it, every change is audited', function () {
    T::freshDb();
    $s = AdminServices::save(null, ac_base(), ac_req(), AC_ADMIN);
    $id = $s['id'];
    T::same(null, $s['fulfil_cost_paise'], 'not stated by default');
    T::false($s['preview']['fulfil_known']);
    T::same(1250, $s['preview']['gross'], 'nothing is deducted for a number we do not have');
    $s = AdminServices::save($id, ['fulfil_cost' => '3.50'], ac_req(), AC_ADMIN);
    T::same([350, '3.50', 900, true], [$s['fulfil_cost_paise'], $s['fulfil_cost'], $s['preview']['gross'], $s['preview']['fulfil_known']]);
    $s = AdminServices::save($id, ['title' => 'Renamed'], ac_req(), AC_ADMIN);
    T::same(350, $s['fulfil_cost_paise'], 'a save that does not mention it keeps it');
    $s = AdminServices::save($id, ['fulfil_cost' => '0'], ac_req(), AC_ADMIN);
    T::same([0, '0.00', true], [$s['fulfil_cost_paise'], $s['fulfil_cost'], $s['preview']['fulfil_known']], 'zero is a number that was stated');
    $s = AdminServices::save($id, ['fulfil_cost' => '  '], ac_req(), AC_ADMIN);
    T::same(null, $s['fulfil_cost_paise'], 'empty goes back to not stated');
    foreach (['abc', '-1', '1.999', '1e3', '999999999', '১২'] as $bad) {
        T::true(isset(ac_fields(fn() => AdminServices::save($id, ['fulfil_cost' => $bad], ac_req(), AC_ADMIN))['fulfil_cost']), 'rejects ' . json_encode($bad));
    }
    T::same(null, AdminServices::get($id)['fulfil_cost_paise'], 'a refused save changes nothing');
    $f = AdminServices::save($id, ['fulfil_cost' => 2.5], ac_req(), AC_ADMIN);
    T::same(250, $f['fulfil_cost_paise'], 'a JSON number is read as rupees too');
    $rows = Db::all("SELECT meta_json FROM audit_logs WHERE action = 'service.fulfil_cost_changed' ORDER BY id");
    T::same(4, count($rows), 'null → 3.50 → 0.00 → null → 2.50, each change once');
    $last = json_decode((string) $rows[3]['meta_json'], true);
    T::same([null, '2.50'], [$last['from'], $last['to']]);
    // the list used by the admin carries it too
    $row = array_values(array_filter(AdminServices::list(), static fn(array $x): bool => $x['id'] === $id))[0];
    T::same(250, $row['fulfil_cost_paise']);
});

T::test('admin services: optimistic locking refuses a stale form (409) and soft delete frees the code but keeps order history intact', function () {
    T::freshDb();
    $id = (int) Db::val("SELECT id FROM services WHERE code = 'ig-likes-100'");
    $stale = (string) Db::val('SELECT updated_at FROM services WHERE id = ?', [$id]);
    Db::exec('UPDATE services SET updated_at = ? WHERE id = ?', ['2031-01-01 00:00:00', $id]);
    T::throws(HttpException::class, fn() => AdminServices::save($id, ['title' => 'X', 'expected_updated_at' => $stale], ac_req(), AC_ADMIN), 409);
    AdminServices::delete($id, ac_req(), AC_ADMIN);
    T::same(1, (int) Db::val('SELECT COUNT(*) FROM services WHERE id = ? AND deleted_at IS NOT NULL', [$id]), 'row kept for order history');
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM services WHERE code = 'ig-likes-100'"), 'code freed');
    $again = AdminServices::save(null, ac_base(['code' => 'ig-likes-100', 'title' => '100 Likes']), ac_req(), AC_ADMIN);
    T::true($again['id'] !== $id);
    T::throws(HttpException::class, fn() => AdminServices::get($id), 404);
});

T::test('admin settings: website validation (name, colours incl. CSS-injection attempts, contrast, support link, timezone lock)', function () {
    T::freshDb();
    Tz::set('Asia/Kolkata');
    $save = fn(array $in) => ac_fields(fn() => AdminSettings::saveWebsite($in, ac_req(), AC_ADMIN));
    T::true(isset($save(['name' => 'A'])['name']));
    T::true(isset($save(['name' => str_repeat('n', 61)])['name']));
    foreach (['red', '#12345', '#ff2d87;}body{display:none', 'url(javascript:1)', '#ff2d87</style><script>'] as $bad) {
        T::true(isset($save(['accent_from' => $bad])['accent_from']), $bad);
    }
    T::true(isset($save(['accent_to' => '#ffff00'])['accent_to']), 'too light');
    T::true(isset($save(['support_link' => 'javascript:alert(1)'])['support_link']));
    T::true(isset($save(['timezone' => 'Mars/Base'])['timezone']));
    T::same([], $save(['name' => 'Renamed', 'accent_from' => '#C4005F', 'support_link' => 'https://wa.me/911234567890', 'maintenance' => 'true']));
    T::same('Renamed', Settings::get('site.name'));
    T::same('#c4005f', Settings::get('theme.accent_from'), 'stored lower-case');
    T::true(Settings::get('site.maintenance') === true);
    T::same('Asia/Kolkata', AdminSettings::website()['timezone']);
    // time zone is editable only while the shop has no orders/visitors
    T::same([], $save(['timezone' => 'UTC']));
    Tz::reset();
    Settings::set('business.timezone', 'Asia/Kolkata');
    Db::insert('visitors', ['anon_id' => bin2hex(random_bytes(16)), 'first_seen_at' => \App\Support\Clock::sql(), 'last_seen_at' => \App\Support\Clock::sql()]);
    $vid = (int) Db::val('SELECT id FROM visitors LIMIT 1');
    Db::insert('visitor_days', ['visitor_id' => $vid, 'day' => '2030-01-01', 'created_at' => \App\Support\Clock::sql()]);
    T::true(isset($save(['timezone' => 'UTC'])['timezone']), 'locked once data exists');
});

T::test('admin settings: platforms keep at least one active; names validated; slug immutable', function () {
    T::freshDb();
    $ig = (int) Db::val("SELECT id FROM platforms WHERE slug = 'instagram'");
    $yt = (int) Db::val("SELECT id FROM platforms WHERE slug = 'youtube'");
    T::same('Insta', AdminSettings::savePlatform($ig, ['name' => 'Insta'], ac_req(), AC_ADMIN)['name']);
    T::same('instagram', (string) Db::val('SELECT slug FROM platforms WHERE id = ?', [$ig]));
    T::false(AdminSettings::savePlatform($ig, ['is_active' => false], ac_req(), AC_ADMIN)['is_active'], 'platform hidden');
    $f = ac_fields(fn() => AdminSettings::savePlatform($yt, ['is_active' => false], ac_req(), AC_ADMIN));
    T::true(isset($f['is_active']), 'cannot hide the last active platform');
    T::true(isset(ac_fields(fn() => AdminSettings::savePlatform($ig, ['name' => 'X'], ac_req(), AC_ADMIN))['name']));
});

T::test('advertising: CPC edits are validated, versioned (history), and the previous CPC stays on past visitor-days', function () {
    T::freshDb();
    $f = fn($cpc, $secs = 3, $conv = 4) => ac_fields(fn() => Ads::update($cpc, $secs, $conv, ac_req(), AC_ADMIN));
    foreach (['abc', '1.234', '-1', '1001', '', null] as $bad) {
        T::true(isset($f($bad)['cpc']), json_encode($bad));
    }
    T::true(isset($f('1.25', 0)['qualify_seconds']));
    T::true(isset($f('1.25', 61)['qualify_seconds']));
    T::true(isset($f('1.25', 3, 0)['assumed_conversion']));
    T::true(isset($f('1.25', 3, 101)['assumed_conversion']));
    T::same(108, Ads::cpcPaise());
    T::same([], $f('1.25'));
    T::same(125, Ads::cpcPaise());
    T::same(2, count(Ads::history()));
});

// ───────────────────────────── database updates (Admin → System) ─────────────────────────────
T::test('admin system: "Apply database updates" applies a pending migration once, audits it, and is a harmless no-op when repeated', function () {
    T::freshDb();
    $ctl = new App\Controllers\Admin\AccountController();
    $req = Request::fake('POST', '/admin/api/system/migrate');
    $req->attributes['admin'] = AC_ADMIN;
    $file = SMB_ROOT . '/database/migrations/999_qa_tmp.sql';
    file_put_contents($file, "CREATE TABLE IF NOT EXISTS qa_tmp_migration (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n");
    try {
        T::same(['999_qa_tmp'], App\Support\Migrator::pending());
        T::same(1, App\Services\AdminAccount::status()['pending_migrations'], 'the admin banner flag');
        $res = $ctl->migrate($req, []);
        $body = json_decode($res->body, true);
        T::same(['999_qa_tmp'], $body['applied']);
        T::same([], $body['pending']);
        T::same(1, (int) Db::val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_tmp_migration'"));
        T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'system.migrated'"));
        $again = json_decode($ctl->migrate($req, [])->body, true);
        T::same([], $again['applied']);
        T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'system.migrated'"), 'nothing applied → nothing audited');
        T::same(0, App\Services\AdminAccount::status()['pending_migrations']);
    } finally {
        @unlink($file);
        Db::exec('DROP TABLE IF EXISTS qa_tmp_migration');
        Db::exec("DELETE FROM schema_migrations WHERE version = '999_qa_tmp'");
    }
});
