<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\View;
use App\Services\AdminServices;
use App\Services\Catalog;
use App\Support\Migrator;

/**
 * The red TRIAL PACK badge: derived from the configured selling price (exactly ₹5), never from a service name or a stored text.
 */

const TP_ADMIN = ['id' => 1, 'username' => 'tester', 'email' => 't@example.test'];
function tp_req(): Request { return Request::fake('PUT', '/admin/api/x'); }

/** @return array<string,array<string,mixed>> every storefront card keyed by service code (needs the code → id map) */
function tp_cards(): array
{
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

function tp_card(string $code): array
{
    return tp_cards()[$code] ?? throw new AssertionError("service {$code} is not on the storefront");
}

function tp_html(array $card): string
{
    return View::partial('service-card', ['s' => $card, 'p' => ['slug' => $card['platform_slug'], 'logo_url' => '/x.webp']]);
}

function tp_base(array $over = []): array
{
    return $over + ['platform_id' => (int) Db::val("SELECT id FROM platforms WHERE slug = 'instagram'"), 'type' => 'followers', 'target_kind' => 'profile', 'title' => 'Whatever Pack',
        'code' => 'qa-trial', 'price' => '5', 'quantity' => '10', 'min_quantity' => '1', 'max_quantity' => '1000000', 'mode' => 'MANUAL', 'status' => 'active'];
}

T::test('trial pack: only a price of exactly ₹5 (500 paise) counts', function () {
    T::true(Catalog::isTrial(500));
    foreach ([0, 1, 100, 499, 501, 550, 5000, 50_000, 10_000_000] as $paise) {
        T::false(Catalog::isTrial($paise), "{$paise} paise is not a trial price");
    }
    T::same(500, Catalog::TRIAL_PRICE_PAISE);
    T::same('TRIAL PACK', Catalog::TRIAL_BADGE);
});

T::test('trial pack: the reserved text is recognised whatever its case or spacing, and is never a "custom" badge', function () {
    foreach (['TRIAL PACK', 'trial pack', ' Trial   Pack ', "TRIAL\tPACK", 'tRiAl pAcK'] as $t) {
        T::true(Catalog::isTrialText($t), json_encode($t));
        T::same(null, Catalog::customBadge($t), json_encode($t));
    }
    foreach (['TRIAL PACK 2', 'TRIAL', 'PACK', 'BEST SELLER', 'TRIALPACK', 'NOT A TRIAL PACK'] as $t) {
        T::false(Catalog::isTrialText($t), json_encode($t));
        T::same($t, Catalog::customBadge($t));
    }
    T::same(null, Catalog::customBadge(null));
    T::same(null, Catalog::customBadge(''));
    T::same(null, Catalog::customBadge("  \n"));
    T::same('HOT', Catalog::customBadge('  HOT '));
});

T::test('trial pack: on a fresh catalogue EVERY ₹5 service on both platforms has it and nothing else does', function () {
    T::freshDb();
    $cards = tp_cards();
    T::same(14, count($cards));
    $five = array_keys(array_filter($cards, static fn(array $c): bool => $c['price_paise'] === 500));
    sort($five);
    T::same(['ig-followers-50', 'ig-likes-100', 'ig-views-1k', 'yt-likes-100', 'yt-subs-50', 'yt-views-10'], $five, 'the six seeded ₹5 packs');
    foreach ($cards as $code => $c) {
        T::same($c['price_paise'] === 500, $c['trial'], "{$code}: trial flag follows the price");
        T::same(500 === $c['price_paise'] ? 1 : 0, substr_count(tp_html($c), 'svc-badge-trial'), "{$code}: exactly one trial badge element iff ₹5");
    }
    T::same(3, count(array_filter($five, static fn(string $c): bool => str_starts_with($c, 'ig-'))), 'three on Instagram');
    T::same(3, count(array_filter($five, static fn(string $c): bool => str_starts_with($c, 'yt-'))), 'three on YouTube');
    T::same('PREMIUM', $cards['yt-watch-4000h']['badge'], 'the premium card keeps its own badge');
    T::false($cards['yt-watch-4000h']['trial']);
});

T::test('trial pack: the seed no longer stores the text (it would outlive a price change)', function () {
    T::freshDb();
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM services WHERE UPPER(TRIM(badge)) = 'TRIAL PACK'"));
    T::same(null, Db::val("SELECT badge FROM services WHERE code = 'ig-followers-50'"));
});

T::test('trial pack: follows the configured price — appears at ₹5, disappears at any other amount, comes back at ₹5 again; service names are irrelevant', function () {
    T::freshDb();
    $s = AdminServices::save(null, tp_base(), tp_req(), TP_ADMIN);          // a brand-new service nobody hard-coded
    T::true(tp_card('qa-trial')['trial'], '₹5 → badge');
    T::same(1, substr_count(tp_html(tp_card('qa-trial')), 'TRIAL PACK'));
    foreach (['5.01', '4.99', '6', '50', '1', '5.50'] as $price) {
        AdminServices::save($s['id'], ['price' => $price], tp_req(), TP_ADMIN);
        T::false(tp_card('qa-trial')['trial'], "₹{$price} → no badge");
        T::same(0, substr_count(tp_html(tp_card('qa-trial')), 'TRIAL PACK'), "₹{$price} → none rendered");
    }
    AdminServices::save($s['id'], ['price' => '5.00'], tp_req(), TP_ADMIN);
    T::true(tp_card('qa-trial')['trial'], '₹5.00 → badge again');
    // an existing seeded service: re-price it away and back
    $id = (int) Db::val("SELECT id FROM services WHERE code = 'ig-followers-50'");
    AdminServices::save($id, ['price' => '7'], tp_req(), TP_ADMIN);
    T::false(tp_card('ig-followers-50')['trial']);
    AdminServices::save($id, ['price' => '5'], tp_req(), TP_ADMIN);
    T::true(tp_card('ig-followers-50')['trial']);
    // and the other way: a ₹99 service becomes a trial the moment it costs ₹5
    $big = (int) Db::val("SELECT id FROM services WHERE code = 'yt-subs-100k'");
    T::false(tp_card('yt-subs-100k')['trial']);
    AdminServices::save($big, ['price' => '5'], tp_req(), TP_ADMIN);
    T::true(tp_card('yt-subs-100k')['trial']);
});

T::test('trial pack: a stored "TRIAL PACK" text never shows by itself (price decides) and never doubles the badge', function () {
    T::freshDb();
    Db::exec("UPDATE services SET badge = ' trial  pack ', badge_color = 'red' WHERE code IN ('ig-followers-50', 'yt-subs-100k')");
    $five = tp_card('ig-followers-50');
    T::true($five['trial']);
    T::same(null, $five['badge'], 'the stored copy is ignored, not shown a second time');
    T::same(1, substr_count(tp_html($five), 'TRIAL PACK'), 'one badge on the ₹5 card');
    $big = tp_card('yt-subs-100k');
    T::false($big['trial']);
    T::same(null, $big['badge']);
    T::same(0, substr_count(tp_html($big), 'TRIAL PACK'), 'no badge on a ₹99 card even though the old text is stored');
});

T::test('trial pack: an extra custom badge on a ₹5 card sits beside it — two different badges, never two of the same', function () {
    T::freshDb();
    Db::exec("UPDATE services SET badge = 'HOT', badge_color = 'amber' WHERE code = 'ig-likes-100'");
    $c = tp_card('ig-likes-100');
    T::true($c['trial']);
    T::same('HOT', $c['badge']);
    $html = tp_html($c);
    T::same(1, substr_count($html, 'data-badge="trial"'));
    T::same(1, substr_count($html, 'data-badge="custom"'));
    T::same(1, substr_count($html, 'TRIAL PACK'));
    T::contains('badge-amber', $html);
    // the same text typed in the custom slot is not shown twice either
    Db::exec("UPDATE services SET badge = 'Trial Pack' WHERE code = 'ig-likes-100'");
    T::same(1, substr_count(tp_html(tp_card('ig-likes-100')), 'TRIAL PACK'));
    // a custom badge keeps working on its own at any other price
    Db::exec("UPDATE services SET badge = 'NEW', badge_color = 'blue' WHERE code = 'yt-subs-100k'");
    $n = tp_card('yt-subs-100k');
    T::false($n['trial']);
    T::same('NEW', $n['badge']);
    T::same(0, substr_count(tp_html($n), 'data-badge="trial"'));
});

T::test('trial pack: card markup — badge is escaped, sits in one badge container and only the TRIAL element carries the trial class', function () {
    T::freshDb();
    Db::exec("UPDATE services SET badge = '<b>x</b> & \"y\"', badge_color = 'nope' WHERE code = 'ig-views-1k'");
    $html = tp_html(tp_card('ig-views-1k'));
    T::false(str_contains($html, '<b>x</b>'), 'custom text is HTML-escaped');
    T::contains('&lt;b&gt;x&lt;/b&gt;', $html);
    T::contains('badge-red', $html, 'an unknown colour falls back to red');
    T::same(1, substr_count($html, 'class="svc-badges"'));
    T::same(1, substr_count($html, 'svc-badge-trial'));
    $plain = tp_html(tp_card('ig-likes-50k'));
    T::same(0, substr_count($plain, 'svc-badges'), 'no container at all when there is nothing to show');
});

T::test('trial pack admin: typing the reserved text is rejected, other text is fine, and the admin API reports the automatic badge', function () {
    T::freshDb();
    foreach (['TRIAL PACK', 'trial pack', '  Trial   Pack '] as $text) {
        try {
            AdminServices::save(null, tp_base(['badge' => $text, 'code' => 'qa-typed']), tp_req(), TP_ADMIN);
            throw new AssertionError('expected rejection for ' . json_encode($text));
        } catch (HttpException $e) {
            T::same(422, $e->status);
            T::true(isset($e->extra['fields']['badge']), 'the badge field is flagged');
            T::contains('automatically', $e->extra['fields']['badge']);
        }
    }
    T::same(0, (int) Db::val("SELECT COUNT(*) FROM services WHERE code = 'qa-typed'"), 'nothing was saved');
    $ok = AdminServices::save(null, tp_base(['badge' => 'BEST SELLER', 'badge_color' => 'green', 'code' => 'qa-ok']), tp_req(), TP_ADMIN);
    T::same('BEST SELLER', $ok['badge']);
    T::true($ok['trial_pack'], 'a ₹5 service reports the automatic badge to the admin list');
    $pricey = AdminServices::save($ok['id'], ['price' => '9'], tp_req(), TP_ADMIN);
    T::false($pricey['trial_pack']);
    T::same('BEST SELLER', $pricey['badge'], 'the custom badge is untouched by a price change');
    $meta = AdminServices::meta();
    T::same(['label' => 'TRIAL PACK', 'price_paise' => 500, 'price' => '₹5'], $meta['trial_pack']);
});

T::test('trial pack admin: a legacy stored copy is hidden in the editor and cleaned by any save — including partial updates that omit the badge', function () {
    T::freshDb();
    Db::exec("UPDATE services SET badge = 'TRIAL PACK', badge_color = 'red' WHERE code = 'ig-followers-50'");
    $id = (int) Db::val("SELECT id FROM services WHERE code = 'ig-followers-50'");
    $view = AdminServices::get($id);
    T::same('', $view['badge'], 'the editor does not offer the reserved text for editing');
    T::same('', $view['badge_color']);
    T::true($view['trial_pack']);
    // a partial update (no "badge" key) must not trip over the stored copy …
    $saved = AdminServices::save($id, ['title' => '50 Followers!'], tp_req(), TP_ADMIN);
    T::same('50 Followers!', $saved['title']);
    // … and drops it
    T::same(null, Db::val('SELECT badge FROM services WHERE id = ?', [$id]));
    T::same(null, Db::val('SELECT badge_color FROM services WHERE id = ?', [$id]));
    T::true(tp_card('ig-followers-50')['trial'], 'the badge is still there — it comes from the price');
});

T::test('trial pack migration 002: clears the stored copies (any case/spacing) and leaves every other badge alone; safe to re-run', function () {
    T::freshDb();
    Db::exec("UPDATE services SET badge = 'TRIAL PACK', badge_color = 'red' WHERE code = 'ig-followers-50'");
    Db::exec("UPDATE services SET badge = ' trial pack ', badge_color = 'red' WHERE code = 'ig-likes-100'");
    Db::exec("UPDATE services SET badge = 'TRIAL PACK 2', badge_color = 'blue' WHERE code = 'ig-views-1k'");
    Db::exec("UPDATE services SET badge = 'BEST SELLER', badge_color = 'green' WHERE code = 'yt-subs-50'");
    $file = SMB_ROOT . '/database/migrations/002_trial_pack_is_automatic.sql';
    T::true(is_file($file));
    for ($run = 0; $run < 2; $run++) {
        foreach (Migrator::splitStatements((string) file_get_contents($file)) as $sql) {
            Db::pdo()->exec($sql);
        }
    }
    T::same(null, Db::val("SELECT badge FROM services WHERE code = 'ig-followers-50'"));
    T::same(null, Db::val("SELECT badge_color FROM services WHERE code = 'ig-followers-50'"));
    T::same(null, Db::val("SELECT badge FROM services WHERE code = 'ig-likes-100'"));
    T::same('TRIAL PACK 2', Db::val("SELECT badge FROM services WHERE code = 'ig-views-1k'"));
    T::same('blue', Db::val("SELECT badge_color FROM services WHERE code = 'ig-views-1k'"));
    T::same('BEST SELLER', Db::val("SELECT badge FROM services WHERE code = 'yt-subs-50'"));
    T::same('PREMIUM', Db::val("SELECT badge FROM services WHERE code = 'yt-watch-4000h'"));
    T::same(1, (int) Db::val("SELECT COUNT(*) FROM schema_migrations WHERE version = '002_trial_pack_is_automatic'"), 'recorded as applied');
});
