<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\View;
use App\Services\AdminServices;
use App\Services\Catalog;
use App\Services\Tags;
use App\Support\Color;
use App\Support\Migrator;

/**
 * Service tags & badges: the 10 predefined tags, the admin CRUD rules, assignment to any service, the automatic ₹5 TRIAL PACK rule
 * (priority over the assigned tag, restored when the price changes, integer-paise comparison) and migration 003.
 */

const TG_ADMIN = ['id' => 1, 'username' => 'tester', 'email' => 't@example.test'];
function tg_req(): Request { return Request::fake('PUT', '/admin/api/x'); }

/** @return array<string,array<string,mixed>> storefront card by service code (+ platform_slug) */
function tg_cards(): array
{
    Tags::flush();
    $byId = [];
    foreach (Catalog::storefront() as $p) {
        foreach ($p['sections'] as $sec) {
            foreach ($sec['services'] as $s) {
                $byId[$s['id']] = $s + ['platform_slug' => $p['slug']];
            }
        }
    }
    $out = [];
    foreach (Db::all('SELECT id, code FROM services') as $r) {
        if (isset($byId[(int) $r['id']])) {
            $out[$r['code']] = $byId[(int) $r['id']];
        }
    }
    return $out;
}
function tg_card(string $code): array { return tg_cards()[$code] ?? throw new AssertionError("service {$code} is not on the storefront"); }
function tg_html(array $card): string { return View::partial('service-card', ['s' => $card, 'p' => ['slug' => $card['platform_slug'], 'logo_url' => '/x.webp']]); }
function tg_id(string $code): int { return (int) Db::val('SELECT id FROM service_tags WHERE code = ?', [$code]); }
function tg_svc(string $code): int { return (int) Db::val('SELECT id FROM services WHERE code = ?', [$code]); }
function tg_set(string $serviceCode, ?int $tagId): void { Db::exec('UPDATE services SET tag_id = ? WHERE code = ?', [$tagId, $serviceCode]); Tags::flush(); }
function tg_price(string $serviceCode, string $rupees): void { AdminServices::save(tg_svc($serviceCode), ['price' => $rupees], tg_req(), TG_ADMIN); Tags::flush(); }
function tg_fields(callable $fn): array
{
    try {
        $fn();
    } catch (HttpException $e) {
        T::same(422, $e->status);
        return $e->extra['fields'] ?? [];
    }
    return [];
}
function tg_new(array $over = []): array { return Tags::save(null, $over + ['name' => 'Flash sale', 'bg_color' => '#E11D48', 'text_color' => '#FFFFFF'], tg_req(), TG_ADMIN); }
/** how many badge elements a card shows (a card must never show two) */
function tg_badges(string $html): int { return substr_count($html, 'svc-badge'); }

// ───────────────────────────── the 10 predefined tags ─────────────────────────────

T::test('tags: the 10 predefined tags exist with the specified names, colours and white text', function () {
    T::freshDb();
    $expected = [
        ['TRIAL PACK', '#DC2626'], ['BEST SELLER', '#7C3AED'], ['POPULAR', '#D97706'], ['BEST VALUE', '#15803D'], ['PREMIUM', '#0369A1'],
        ['HOT DEAL', '#BE185D'], ['NON DROP', '#0F766E'], ['INSTANT', '#4338CA'], ['LIMITED OFFER', '#475569'], ['VIP', '#A16207'],
    ];
    $rows = Db::all('SELECT name, bg_color, text_color, is_active, is_system, code FROM service_tags ORDER BY sort_order, id');
    T::same(10, count($rows));
    foreach ($expected as $i => [$name, $bg]) {
        T::same($name, $rows[$i]['name']);
        T::same($bg, $rows[$i]['bg_color'], $name);
        T::same('#FFFFFF', $rows[$i]['text_color'], $name . ' text');
        T::same(1, (int) $rows[$i]['is_active'], $name . ' enabled');
        T::same(1, (int) $rows[$i]['is_system'], $name . ' predefined');
        T::true($rows[$i]['code'] !== null && $rows[$i]['code'] !== '', $name . ' has a stable code');
    }
});

T::test('tags: the PHP list, the seeder and the migration SQL agree on the 10 tags; seeding is idempotent and never overwrites an edit', function () {
    T::freshDb();
    $insert = null;
    foreach (Migrator::splitStatements((string) file_get_contents(SMB_ROOT . '/database/migrations/003_service_tags.sql')) as $stmt) {
        if (str_starts_with($stmt, 'INSERT IGNORE INTO `service_tags`')) {
            $insert = $stmt;
        }
    }
    T::true($insert !== null, 'the migration inserts the predefined tags');
    Db::exec('DELETE FROM service_tags');
    Db::pdo()->exec((string) $insert);
    $fromSql = array_map(static fn(array $r): array => [$r['code'], $r['name'], $r['bg_color'], $r['text_color']], Db::all('SELECT code, name, bg_color, text_color FROM service_tags ORDER BY sort_order, id'));
    T::same(Tags::PREDEFINED, $fromSql, 'migration ≡ Tags::PREDEFINED');
    Db::exec('DELETE FROM service_tags');
    Tags::flush();
    T::same(10, Tags::seedPredefined());
    $fromSeed = array_map(static fn(array $r): array => [$r['code'], $r['name'], $r['bg_color'], $r['text_color']], Db::all('SELECT code, name, bg_color, text_color FROM service_tags ORDER BY sort_order, id'));
    T::same(Tags::PREDEFINED, $fromSeed, 'seeder ≡ Tags::PREDEFINED');
    Tags::save(tg_id('vip'), ['name' => 'V.I.P', 'bg_color' => '#111111'], tg_req(), TG_ADMIN);
    T::same(0, Tags::seedPredefined(), 'second run creates nothing');
    T::same('V.I.P', Db::val("SELECT name FROM service_tags WHERE code = 'vip'"), 'an admin edit survives re-seeding');
});

T::test('tags: only the PREMIUM tag is assigned in the seed (the watch-time card); no other service has a manual tag', function () {
    T::freshDb();
    T::same(['yt-watch-4000h' => 'premium'], array_column(Db::all('SELECT s.code, t.code AS tag FROM services s JOIN service_tags t ON t.id = s.tag_id'), 'tag', 'code'));
});

// ───────────────────────────── the automatic ₹5 rule ─────────────────────────────

T::test('tags: effective(): exactly ₹5 → TRIAL PACK with priority over the assigned tag; any other price → the assigned tag; disabled / absent → none', function () {
    T::freshDb();
    $best = tg_id('best-seller');
    $e = Tags::effective(500, $best);
    T::same(['TRIAL PACK', true], [$e['name'], $e['auto']], '₹5 beats the assigned tag');
    T::same('#DC2626', $e['bg']);
    foreach ([0, 1, 100, 499, 501, 550, 1000, 5000, 19900, 10_000_000] as $paise) {
        $e = Tags::effective($paise, $best);
        T::same(['BEST SELLER', false], [$e['name'], $e['auto']], "{$paise} paise keeps the assigned tag");
    }
    T::same('TRIAL PACK', Tags::effective(500, null)['name'], '₹5 with nothing assigned');
    T::same(null, Tags::effective(1000, null), 'no tag, not ₹5');
    T::same(null, Tags::effective(1000, 999_999), 'a vanished tag id shows nothing');
    // a disabled assigned tag is hidden, its assignment is untouched
    Db::exec("UPDATE service_tags SET is_active = 0 WHERE code = 'best-seller'");
    Tags::flush();
    T::same(null, Tags::effective(1000, $best), 'disabled → hidden');
    T::same('TRIAL PACK', Tags::effective(500, $best)['name'], 'the automatic rule is unaffected by it');
    // TRIAL PACK assigned by hand never shows at another price
    T::same(null, Tags::effective(1000, tg_id('trial-pack')), 'the automatic tag is automatic only');
    // disabling TRIAL PACK itself turns the rule off: the assigned tag is shown at ₹5 again
    Db::exec("UPDATE service_tags SET is_active = 1 WHERE code = 'best-seller'");
    Db::exec("UPDATE service_tags SET is_active = 0 WHERE code = 'trial-pack'");
    Tags::flush();
    T::same(['BEST SELLER', false], [Tags::effective(500, $best)['name'], Tags::effective(500, $best)['auto']]);
    T::same(null, Tags::effective(500, null));
});

T::test('tags: the rule survives a rename of TRIAL PACK (it is bound to the tag, not to its name)', function () {
    T::freshDb();
    Tags::save(tg_id('trial-pack'), ['name' => 'STARTER'], tg_req(), TG_ADMIN);
    T::same('STARTER', tg_card('ig-likes-100')['tag']['name']);
    T::true(tg_card('ig-likes-100')['tag']['auto']);
});

T::test('tags: storefront — every ₹5 service on both platforms shows TRIAL PACK, one badge per card, and nothing else does', function () {
    T::freshDb();
    $cards = tg_cards();
    $five = [];
    foreach ($cards as $code => $c) {
        if ($c['price_paise'] === 500) {
            $five[] = $code;
            T::same(['TRIAL PACK', true], [$c['tag']['name'], $c['tag']['auto']], $code);
            T::same(1, tg_badges(tg_html($c)), "{$code}: exactly one badge element");
            T::contains('tag-' . tg_id('trial-pack'), tg_html($c), $code);
        } elseif ($code !== 'yt-watch-4000h') {
            T::same(null, $c['tag'], "{$code} has no badge");
            T::same(0, tg_badges(tg_html($c)), "{$code}: no badge element");
        }
    }
    sort($five);
    T::same(['ig-followers-50', 'ig-likes-100', 'ig-views-1k', 'yt-likes-100', 'yt-subs-50', 'yt-views-10'], $five);
});

T::test('tags: price changes — ₹5 → ₹10 restores the assigned tag, ₹10 → ₹5 hides it again; 4.99 / 5.01 are not ₹5; 5, 5.0, 5.00 are', function () {
    T::freshDb();
    tg_set('ig-likes-100', tg_id('best-seller'));                       // a ₹5 service with a manual tag
    T::same(['TRIAL PACK', true], [tg_card('ig-likes-100')['tag']['name'], tg_card('ig-likes-100')['tag']['auto']], 'at ₹5 the automatic rule wins');
    T::same(1, tg_badges(tg_html(tg_card('ig-likes-100'))), 'and the manual tag is not shown beside it');
    tg_price('ig-likes-100', '10');
    T::same(['BEST SELLER', false], [tg_card('ig-likes-100')['tag']['name'], tg_card('ig-likes-100')['tag']['auto']], '₹10 → the manual tag is restored');
    T::same(1, tg_badges(tg_html(tg_card('ig-likes-100'))));
    foreach (['4.99', '5.01', '5.50', '50', '1'] as $price) {
        tg_price('ig-likes-100', $price);
        T::same('BEST SELLER', tg_card('ig-likes-100')['tag']['name'], "₹{$price} is not ₹5");
    }
    foreach (['5', '5.0', '5.00', ' 5.00 '] as $price) {
        tg_price('ig-likes-100', '10');
        tg_price('ig-likes-100', $price);
        T::same('TRIAL PACK', tg_card('ig-likes-100')['tag']['name'], 'priced ' . json_encode($price));
    }
    // JSON numbers from a client become exact integer paise (6-decimal rendering, then the same string parser): 5.0 is ₹5, 4.99 is ₹4.99,
    // more than two decimals are refused instead of being rounded onto ₹5
    tg_price('ig-likes-100', '10');
    AdminServices::save(tg_svc('ig-likes-100'), ['price' => 5.0], tg_req(), TG_ADMIN);
    T::same('TRIAL PACK', tg_card('ig-likes-100')['tag']['name'], 'JSON 5.0');
    AdminServices::save(tg_svc('ig-likes-100'), ['price' => 4.99], tg_req(), TG_ADMIN);
    T::same('BEST SELLER', tg_card('ig-likes-100')['tag']['name'], 'JSON 4.99 is 499 paise, not 500');
    foreach ([4.9999, 5.001, 4.995] as $float) {
        $f = tg_fields(fn() => AdminServices::save(tg_svc('ig-likes-100'), ['price' => $float], tg_req(), TG_ADMIN));
        T::true(isset($f['price']), json_encode($float) . ' has more than two decimals');
    }
    // a dearer service becomes a trial the moment it costs ₹5; names play no part
    T::same(null, tg_card('yt-subs-100k')['tag']);
    tg_price('yt-subs-100k', '5');
    T::same('TRIAL PACK', tg_card('yt-subs-100k')['tag']['name']);
    tg_price('yt-subs-100k', '99');
    T::same(null, tg_card('yt-subs-100k')['tag']);
});

// ───────────────────────────── assigning tags ─────────────────────────────

T::test('tags: each of the 9 manual predefined tags can be assigned to an Instagram and a YouTube service and renders with its own colours', function () {
    T::freshDb();
    $codes = ['best-seller', 'popular', 'best-value', 'premium', 'hot-deal', 'non-drop', 'instant', 'limited-offer', 'vip'];
    foreach (['ig-followers-100k' => 'instagram', 'yt-subs-100k' => 'youtube'] as $svc => $platform) {
        foreach ($codes as $code) {
            $saved = AdminServices::save(tg_svc($svc), ['tag_id' => tg_id($code)], tg_req(), TG_ADMIN);
            T::same(tg_id($code), $saved['tag_id']);
            $card = tg_card($svc);
            T::same($platform, $card['platform_slug']);
            T::same(['id' => tg_id($code), 'name' => Db::val('SELECT name FROM service_tags WHERE code = ?', [$code]), 'bg' => Db::val('SELECT bg_color FROM service_tags WHERE code = ?', [$code]), 'fg' => '#FFFFFF', 'auto' => false], $card['tag'], "{$svc} · {$code}");
            $html = tg_html($card);
            T::same(1, tg_badges($html), "{$svc} · {$code}: one badge");
            T::contains('tag-' . tg_id($code), $html);
            T::contains('data-badge="tag"', $html);
        }
        // "No Tag"
        foreach ([null, '', 0, '0'] as $none) {
            AdminServices::save(tg_svc($svc), ['tag_id' => tg_id('vip')], tg_req(), TG_ADMIN);
            $saved = AdminServices::save(tg_svc($svc), ['tag_id' => $none], tg_req(), TG_ADMIN);
            T::same(null, $saved['tag_id'], 'No Tag via ' . json_encode($none));
            T::same(null, tg_card($svc)['tag']);
            T::same(0, tg_badges(tg_html(tg_card($svc))));
        }
    }
});

T::test('tags: assignment validation — the automatic tag, unknown ids, junk and newly assigned disabled tags are refused; partial updates leave the tag alone', function () {
    T::freshDb();
    $svc = tg_svc('ig-followers-100k');
    $f = tg_fields(fn() => AdminServices::save($svc, ['tag_id' => tg_id('trial-pack')], tg_req(), TG_ADMIN));
    T::contains('automatic', $f['tag_id'] ?? '');
    foreach ([[999_999, 'no longer exists'], ['abc', 'Choose a tag'], ['1e3', 'Choose a tag'], [-5, 'Choose a tag'], [1.5, 'Choose a tag'], [[1], 'Choose a tag'], [true, 'Choose a tag']] as [$bad, $message]) {
        $f = tg_fields(fn() => AdminServices::save($svc, ['tag_id' => $bad], tg_req(), TG_ADMIN));
        T::contains($message, $f['tag_id'] ?? '', 'tag_id ' . json_encode($bad) . ' must be rejected');
    }
    T::same(null, Db::val('SELECT tag_id FROM services WHERE id = ?', [$svc]), 'nothing was saved');
    // assign, then disable the tag: the assignment stays and further saves of the service keep it
    AdminServices::save($svc, ['tag_id' => tg_id('popular')], tg_req(), TG_ADMIN);
    Tags::save(tg_id('popular'), ['is_active' => false], tg_req(), TG_ADMIN);
    $saved = AdminServices::save($svc, ['title' => '100K Followers!'], tg_req(), TG_ADMIN);
    T::same(tg_id('popular'), $saved['tag_id'], 'a partial update keeps a disabled tag assigned');
    T::same(null, $saved['effective_tag'], 'but it is not shown');
    $saved = AdminServices::save($svc, ['tag_id' => tg_id('popular'), 'title' => '100K Followers'], tg_req(), TG_ADMIN);
    T::same(tg_id('popular'), $saved['tag_id'], 'sending the same disabled tag again is fine');
    $f = tg_fields(fn() => AdminServices::save(tg_svc('yt-subs-100k'), ['tag_id' => tg_id('popular')], tg_req(), TG_ADMIN));
    T::contains('disabled', $f['tag_id'] ?? '', 'a disabled tag cannot be newly assigned');
    // the admin view carries what the editor needs
    $v = AdminServices::get($svc);
    T::same([tg_id('popular'), null], [$v['tag_id'], $v['effective_tag']]);
    $meta = AdminServices::meta();
    T::same(10, count($meta['tags']));
    T::same(['code' => 'trial-pack', 'tag_id' => tg_id('trial-pack'), 'price_paise' => 500, 'price' => '₹5'], $meta['tag_rule']);
});

T::test('tags: disabled tags are hidden on the store but keep their assignments; enabling brings them back; the generated CSS follows', function () {
    T::freshDb();
    tg_set('ig-followers-100k', tg_id('hot-deal'));
    tg_set('yt-subs-100k', tg_id('hot-deal'));
    $v1 = Tags::cssVersion();
    T::contains('.tag-' . tg_id('hot-deal') . '{--tag-bg:#BE185D;--tag-fg:#FFFFFF', Tags::css());
    Tags::save(tg_id('hot-deal'), ['is_active' => false], tg_req(), TG_ADMIN);
    T::same(null, tg_card('ig-followers-100k')['tag']);
    T::same(null, tg_card('yt-subs-100k')['tag']);
    T::same(2, (int) Db::val('SELECT COUNT(*) FROM services WHERE tag_id = ?', [tg_id('hot-deal')]), 'both assignments are still there');
    T::false(str_contains(Tags::css(), '.tag-' . tg_id('hot-deal') . '{'), 'no CSS for a disabled tag');
    T::true(Tags::cssVersion() !== $v1, 'the stylesheet version changes');
    Tags::save(tg_id('hot-deal'), ['is_active' => true], tg_req(), TG_ADMIN);
    T::same('HOT DEAL', tg_card('ig-followers-100k')['tag']['name']);
    T::same('HOT DEAL', tg_card('yt-subs-100k')['tag']['name']);
    T::same($v1, Tags::cssVersion(), 'and back to the same version');
});

// ───────────────────────────── managing tags ─────────────────────────────

T::test('tags admin: create a custom tag — normalised, persisted, listed after the predefined ones and usable on a service', function () {
    T::freshDb();
    $t = tg_new(['name' => "  Flash \t  sale ", 'bg_color' => 'e11d48', 'text_color' => '#fff']);
    T::same(['Flash sale', '#E11D48', '#FFFFFF', true, false, null, 0], [$t['name'], $t['bg_color'], $t['text_color'], $t['is_active'], $t['is_system'], $t['code'], $t['usage']]);
    T::same(11, count(Tags::adminList()));
    T::same('Flash sale', Tags::adminList()[10]['name'], 'custom tags come after the predefined ones');
    Tags::flush();                                                       // a new request: everything comes from MySQL
    T::same('#E11D48', Tags::get($t['id'])['bg_color']);
    AdminServices::save(tg_svc('ig-followers-100k'), ['tag_id' => $t['id']], tg_req(), TG_ADMIN);
    $card = tg_card('ig-followers-100k');
    T::same('Flash sale', $card['tag']['name']);
    T::contains('>Flash sale</span>', tg_html($card));
    T::same(1, Tags::adminGet($t['id'])['usage']);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'tag.created'"));
});

T::test('tags admin: names — 2–24 characters, unique (case-insensitive, also against the predefined ones), no markup or control characters', function () {
    T::freshDb();
    foreach (['', ' ', 'A', str_repeat('x', 25), '<b>x</b>', 'hi<script>', "bad\x00name", "tab\x07bell", '!!!', '---', null, 12.5, ['a'], 'ok >'] as $bad) {
        $f = tg_fields(fn() => tg_new(['name' => $bad]));
        T::true(isset($f['name']), 'name ' . json_encode($bad) . ' must be rejected');
    }
    foreach (['Flash sale', 'Ab', str_repeat('Ü', 24), 'R&D "Pro"', "50% OFF!", 'Ünï ✓ 1', '99'] as $good) {
        $t = tg_new(['name' => $good]);
        T::same($good, $t['name']);
        Tags::delete($t['id'], tg_req(), TG_ADMIN);
    }
    foreach (['trial pack', 'TRIAL PACK', 'Best  Seller', ' vip '] as $dup) {
        $f = tg_fields(fn() => tg_new(['name' => $dup]));
        T::true(isset($f['name']), json_encode($dup) . ' collides with a predefined tag');
    }
    tg_new(['name' => 'Flash sale']);
    $f = tg_fields(fn() => tg_new(['name' => 'FLASH SALE']));
    T::contains('already exists', $f['name'] ?? '');
    $other = tg_new(['name' => 'Other']);
    $f = tg_fields(fn() => Tags::save($other['id'], ['name' => 'flash  sale'], tg_req(), TG_ADMIN));
    T::true(isset($f['name']), 'renaming onto an existing name is refused');
    T::same('Other', Tags::adminGet($other['id'])['name']);
    // saving a tag under its own name (different case) is fine
    T::same('OTHER', Tags::save($other['id'], ['name' => 'OTHER'], tg_req(), TG_ADMIN)['name']);
});

T::test('tags admin: colours — only HEX (#RGB or #RRGGBB) is accepted and stored upper-case; names, rgb(), alpha and CSS injection are refused', function () {
    T::freshDb();
    foreach (['red', 'rgb(1,2,3)', '#12', '#1234', '#12345', '#1234567', '#GGGGGG', '#12345678', '', null, 123456, ['#fff'], '#fff;}body{display:none}', 'url(x)', 'expression(1)'] as $bad) {
        $f = tg_fields(fn() => tg_new(['bg_color' => $bad]));
        T::true(isset($f['bg_color']), 'bg ' . json_encode($bad) . ' must be rejected');
        $f = tg_fields(fn() => tg_new(['bg_color' => '#1D4ED8', 'text_color' => $bad]));
        T::true(isset($f['text_color']), 'text ' . json_encode($bad) . ' must be rejected');
    }
    foreach (['#abc' => '#AABBCC', 'abc' => '#AABBCC', '#0f766e' => '#0F766E', '0F766E' => '#0F766E', ' #A16207 ' => '#A16207', "#fff\n" => '#FFFFFF'] as $in => $out) {
        $t = tg_new(['name' => 'C' . bin2hex(random_bytes(3)), 'bg_color' => (string) $in, 'text_color' => '#000000']);
        T::same($out, $t['bg_color']);
        Tags::delete($t['id'], tg_req(), TG_ADMIN);
    }
    // identical or nearly identical text/background colours are refused (< 2:1); white on a mid colour is fine, and the admin can still pick low-ish contrast
    foreach ([['#FFFFFF', '#FFFFFF'], ['#336699', '#336699'], ['#FFFF00', '#FFFFFF'], ['#000000', '#101010']] as [$bg, $fg]) {
        $f = tg_fields(fn() => tg_new(['bg_color' => $bg, 'text_color' => $fg]));
        T::contains('contrast', $f['text_color'] ?? '', "{$fg} on {$bg}");
    }
    $t = tg_new(['bg_color' => '#D97706', 'text_color' => '#FFFFFF']);          // the predefined POPULAR combination: 3.2:1, allowed (the admin only gets a warning)
    T::same(3.19, $t['contrast']);
    T::true(Color::contrast('#DC2626', '#FFFFFF') >= 4.5);
});

T::test('tags admin: edit name and colours of a custom and of a predefined tag; the change reaches every service that uses it', function () {
    T::freshDb();
    $t = tg_new();
    tg_set('ig-followers-100k', $t['id']);
    tg_set('yt-subs-100k', $t['id']);
    $u = Tags::save($t['id'], ['name' => 'Mega deal', 'bg_color' => '#0EA5E9', 'text_color' => '#000000'], tg_req(), TG_ADMIN);
    T::same(['Mega deal', '#0EA5E9', '#000000'], [$u['name'], $u['bg_color'], $u['text_color']]);
    foreach (['ig-followers-100k', 'yt-subs-100k'] as $code) {
        T::same(['id' => $t['id'], 'name' => 'Mega deal', 'bg' => '#0EA5E9', 'fg' => '#000000', 'auto' => false], tg_card($code)['tag']);
    }
    T::contains('--tag-ts:rgb(255 255 255 / 0.35)', Tags::css(), 'dark text gets a light text-shadow');
    // predefined tags are editable too …
    $v = Tags::save(tg_id('vip'), ['name' => 'GOLD', 'bg_color' => '#B45309'], tg_req(), TG_ADMIN);
    T::same(['GOLD', true, ['name' => 'VIP', 'bg_color' => '#A16207', 'text_color' => '#FFFFFF']], [$v['name'], $v['is_system'], $v['default']]);
    // … and a no-op save writes nothing
    $before = (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'tag.updated'");
    Tags::save($t['id'], ['name' => 'Mega deal'], tg_req(), TG_ADMIN);
    T::same($before, (int) Db::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'tag.updated'"));
    T::same(404, tg_status(fn() => Tags::save(999_999, ['name' => 'x1'], tg_req(), TG_ADMIN)));
});
function tg_status(callable $fn): int
{
    try {
        $fn();
    } catch (HttpException $e) {
        return $e->status;
    }
    return 0;
}

T::test('tags admin: enable / disable with a partial update, invalid flags refused; the list reports usage', function () {
    T::freshDb();
    $t = tg_new();
    tg_set('ig-followers-100k', $t['id']);
    T::same(1, Tags::adminGet($t['id'])['usage']);
    $off = Tags::save($t['id'], ['is_active' => false], tg_req(), TG_ADMIN);
    T::same([false, 'Flash sale', '#E11D48'], [$off['is_active'], $off['name'], $off['bg_color']], 'only the flag changed');
    foreach (['0', 0, 'false'] as $falsy) {
        Tags::save($t['id'], ['is_active' => true], tg_req(), TG_ADMIN);
        T::false(Tags::save($t['id'], ['is_active' => $falsy], tg_req(), TG_ADMIN)['is_active'], json_encode($falsy));
    }
    foreach (['yes', 2, [], null, 'on'] as $bad) {
        $f = tg_fields(fn() => Tags::save($t['id'], ['is_active' => $bad], tg_req(), TG_ADMIN));
        T::true(isset($f['is_active']), 'is_active ' . json_encode($bad));
    }
    T::true(Tags::save($t['id'], ['is_active' => '1'], tg_req(), TG_ADMIN)['is_active']);
    // a soft-deleted service no longer counts as a user of the tag
    Db::exec("UPDATE services SET deleted_at = UTC_TIMESTAMP() WHERE code = 'ig-followers-100k'");
    T::same(0, Tags::adminGet($t['id'])['usage']);
});

T::test('tags admin: deleting a custom tag clears it from every service that used it; predefined tags cannot be deleted', function () {
    T::freshDb();
    $t = tg_new();
    foreach (['ig-followers-100k', 'ig-likes-50k', 'yt-subs-100k'] as $code) {
        tg_set($code, $t['id']);
    }
    $stamp = (string) Db::val("SELECT updated_at FROM services WHERE code = 'ig-likes-50k'");
    $r = Tags::delete($t['id'], tg_req(), TG_ADMIN);
    T::same(['unassigned' => 3], $r);
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM service_tags WHERE id = ?', [$t['id']]));
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM services WHERE tag_id IS NOT NULL AND code <> ?', ['yt-watch-4000h']), 'no service points at the deleted tag');
    foreach (['ig-followers-100k', 'ig-likes-50k', 'yt-subs-100k'] as $code) {
        T::same(null, tg_card($code)['tag'], $code);
        T::same(0, tg_badges(tg_html(tg_card($code))));
    }
    T::true((string) Db::val("SELECT updated_at FROM services WHERE code = 'ig-likes-50k'") >= $stamp, 'affected services are touched (their editors will see a conflict)');
    T::contains('"unassigned_services":3', (string) Db::val("SELECT meta_json FROM audit_logs WHERE action = 'tag.deleted' ORDER BY id DESC LIMIT 1"));
    foreach (Db::all('SELECT id, code FROM service_tags WHERE is_system = 1') as $p) {
        $e = T::throws(HttpException::class, static fn() => Tags::delete((int) $p['id'], tg_req(), TG_ADMIN), 422);
        T::same('protected', $e->errorCode, $p['code']);
    }
    T::same(10, (int) Db::val('SELECT COUNT(*) FROM service_tags WHERE is_system = 1'), 'all 10 predefined tags are still there');
    T::throws(HttpException::class, static fn() => Tags::delete(999_999, tg_req(), TG_ADMIN), 404);
});

T::test('tags admin: the foreign key sets the assignment to NULL on its own too (a tag deleted straight in SQL cannot leave a dangling reference)', function () {
    T::freshDb();
    $t = tg_new();
    tg_set('ig-followers-100k', $t['id']);
    Db::exec('DELETE FROM service_tags WHERE id = ?', [$t['id']]);
    T::same(null, Db::val("SELECT tag_id FROM services WHERE code = 'ig-followers-100k'"));
    $e = null;
    try {
        Db::exec("UPDATE services SET tag_id = 987654 WHERE code = 'ig-followers-100k'");
    } catch (\Throwable $e) {
    }
    T::true($e !== null, 'pointing a service at a tag that does not exist is refused by the database');
});

T::test('tags admin: at most 100 tags', function () {
    T::freshDb();
    for ($i = Db::val('SELECT COUNT(*) FROM service_tags'); $i < Tags::MAX_TAGS; $i++) {
        Tags::save(null, ['name' => 'Tag ' . $i, 'bg_color' => '#1D4ED8'], tg_req(), TG_ADMIN);
    }
    T::same(100, (int) Db::val('SELECT COUNT(*) FROM service_tags'));
    $f = tg_fields(fn() => tg_new(['name' => 'One too many']));
    T::contains('at most', $f['name'] ?? '');
});

T::test('tags: escaping — a tag name with quotes and ampersands is HTML-escaped on the card; the stylesheet only ever contains validated HEX values', function () {
    T::freshDb();
    $t = tg_new(['name' => 'R&D "Pro" \'25']);
    tg_set('ig-followers-100k', $t['id']);
    $html = tg_html(tg_card('ig-followers-100k'));
    T::contains('R&amp;D &quot;Pro&quot; &#039;25', $html);
    T::false(str_contains($html, 'R&D "Pro"'), 'never raw');
    foreach (preg_split('/\R/', trim(Tags::css())) as $rule) {
        T::same(1, preg_match('/^\.tag-\d+\{--tag-bg:#[0-9A-F]{6};--tag-fg:#[0-9A-F]{6};--tag-ts:rgb\([0-9 \/.]+\)\}$/D', $rule), $rule);
    }
});

// ───────────────────────────── migration 003 ─────────────────────────────

T::test('schema: database/schema.sql is the current build of the migrations (run `php tools/build-schema.php` after adding or changing one)', function () {
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(SMB_ROOT . '/tools/build-schema.php') . ' --check 2>&1', $out, $code);
    T::same(0, $code, implode("\n", $out));
    $schema = (string) file_get_contents(SMB_ROOT . '/database/schema.sql');
    T::contains('CREATE TABLE IF NOT EXISTS `service_tags`', $schema);
    T::contains("VALUES ('003_service_tags'", $schema);
});

T::test('tags migration 003: old free-text badges become tags (predefined by name, the rest as custom tags coloured like the old colour); TRIAL PACK text is never assigned by hand', function () {
    T::freshDb();
    // put the database back into its pre-003 shape …
    Db::exec('ALTER TABLE services DROP FOREIGN KEY fk_services_tag');
    Db::exec('ALTER TABLE services DROP COLUMN tag_id');
    Db::exec('DROP TABLE service_tags');
    Db::exec("DELETE FROM schema_migrations WHERE version = '003_service_tags'");
    Tags::flush();
    // … with old-style badges
    Db::exec("UPDATE services SET badge = NULL, badge_color = NULL");
    Db::exec("UPDATE services SET badge = 'popular', badge_color = 'amber' WHERE code = 'ig-followers-100k'");      // names a predefined tag (any case)
    Db::exec("UPDATE services SET badge = ' Hot Deal ', badge_color = 'red' WHERE code = 'yt-subs-100k'");           // … with blanks around it
    Db::exec("UPDATE services SET badge = 'FLASH SALE', badge_color = 'green' WHERE code = 'ig-likes-50k'");        // custom (the first service that used the text decides the colour)
    Db::exec("UPDATE services SET badge = 'FLASH SALE', badge_color = 'blue' WHERE code = 'yt-likes-50k'");         // the same custom text → the same tag
    Db::exec("UPDATE services SET badge = 'Mega Deal', badge_color = 'pink' WHERE code = 'ig-views-1m'");
    Db::exec("UPDATE services SET badge = 'MEGA DEAL', badge_color = 'pink' WHERE code = 'yt-subs-50'");            // … whatever its case
    Db::exec("UPDATE services SET badge = 'Odd one', badge_color = 'sparkly' WHERE code = 'yt-views-10k'");           // unknown colour name → red
    Db::exec("UPDATE services SET badge = 'TRIAL PACK', badge_color = 'red' WHERE code = 'ig-blue-tick'");           // automatic only: never assigned
    Db::exec("UPDATE services SET badge = '', badge_color = NULL WHERE code = 'yt-watch-4000h'");                    // empty → no tag
    // a shop whose code was updated before the database was (migration 003 pending) keeps selling: cards render, just without badges,
    // and the admin can still edit a service (the tag is left alone) until Admin → System → Apply database updates has run
    T::same(false, Tags::available(), 'the tags table does not exist yet');
    $cards = tg_cards();
    T::same(14, count($cards), 'every service is still on the storefront');
    foreach ($cards as $code => $card) {
        T::same(null, $card['tag'], "{$code} has no badge before the update");
        T::same(0, tg_badges(tg_html($card)), "{$code} renders without a badge element");
    }
    T::same('', Tags::css(), 'no tag stylesheet rules');
    try {
        (new \App\Controllers\Admin\TagsController())->list(Request::fake('GET', '/admin/api/tags'), []);
        T::true(false, 'the tags API must refuse while the table is missing');
    } catch (HttpException $e) {
        T::same([409, 'migration_pending'], [$e->status, $e->errorCode], 'a plain "apply the database update" answer');
        T::contains('Apply database updates', $e->getMessage());
    }
    $saved = AdminServices::save(tg_svc('ig-followers-100k'), ['price' => '120'], tg_req(), TG_ADMIN);
    T::same(12000, $saved['price_paise'], 'a service can still be saved');
    T::same(null, $saved['tag_id'], 'tag_id is not written while the column does not exist');
    T::contains('no longer exists', tg_fields(fn() => AdminServices::save(tg_svc('ig-followers-100k'), ['tag_id' => 3], tg_req(), TG_ADMIN))['tag_id'] ?? '', 'there are no tags to choose from yet');
    T::same(14, count(AdminServices::list()), 'the admin list works too');
    Db::exec("UPDATE services SET price_paise = 9900 WHERE code = 'ig-followers-100k'");
    T::same(['003_service_tags'], Migrator::run());
    Tags::flush();
    T::same(true, Tags::available());
    $tag = static fn(string $code): ?string => ($r = Db::one('SELECT t.name FROM services s JOIN service_tags t ON t.id = s.tag_id WHERE s.code = ?', [$code])) ? (string) $r['name'] : null;
    T::same('POPULAR', $tag('ig-followers-100k'));
    T::same('HOT DEAL', $tag('yt-subs-100k'));
    T::same('FLASH SALE', $tag('ig-likes-50k'));
    T::same('FLASH SALE', $tag('yt-likes-50k'), 'same text, same tag');
    T::same('MEGA DEAL', strtoupper((string) $tag('ig-views-1m')));
    T::same($tag('ig-views-1m'), $tag('yt-subs-50'), 'Mega Deal / MEGA DEAL are one tag');
    T::same('Odd one', $tag('yt-views-10k'));
    T::same(null, $tag('ig-blue-tick'), 'TRIAL PACK text is not assigned');
    T::same(null, $tag('yt-watch-4000h'));
    T::same(null, $tag('ig-followers-50'));
    $custom = array_column(Db::all('SELECT name, bg_color, text_color, is_active, is_system, code FROM service_tags WHERE is_system = 0 ORDER BY name'), null, 'name');
    T::same(3, count($custom), 'three custom tags: FLASH SALE, Mega Deal, Odd one');
    T::same(['FLASH SALE', 'MEGA DEAL', 'ODD ONE'], array_map('strtoupper', array_keys($custom)));
    $custom = array_change_key_case($custom, CASE_UPPER);
    T::same(['#15803D', '#FFFFFF', 1, 0, null], [$custom['FLASH SALE']['bg_color'], $custom['FLASH SALE']['text_color'], (int) $custom['FLASH SALE']['is_active'], (int) $custom['FLASH SALE']['is_system'], $custom['FLASH SALE']['code']], 'coloured like the old "green"');
    T::same('#C40D5B', $custom['MEGA DEAL']['bg_color']);
    T::same('#DC2626', $custom['ODD ONE']['bg_color']);
    T::same(10, (int) Db::val('SELECT COUNT(*) FROM service_tags WHERE is_system = 1'));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM service_tags WHERE UPPER(name) = 'TRIAL PACK'"), 'no second TRIAL PACK');
    // the old columns are untouched (nothing is dropped)
    T::same('Mega Deal', Db::val("SELECT badge FROM services WHERE code = 'ig-views-1m'"));
    T::same('MEGA DEAL', Db::val("SELECT badge FROM services WHERE code = 'yt-subs-50'"));
    // and the storefront shows the converted tags
    T::same('POPULAR', tg_card('ig-followers-100k')['tag']['name']);
    T::same('FLASH SALE', tg_card('yt-likes-50k')['tag']['name']);
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM schema_migrations WHERE version = '003_service_tags'"));
});
