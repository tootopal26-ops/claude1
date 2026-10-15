<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\HttpException;
use App\Services\AdminOrders;
use App\Services\Catalog;
use App\Services\Orders;

/**
 * Instagram Followers and YouTube Subscribers take the ACCOUNT LINK ("Instagram Profile Link" / "YouTube Channel Link"), not a username.
 * Everything below goes through the real checkout, so it is the server-side rule that is tested (the browser runs the same code:
 * tests/fixtures/targets.json is checked against both, see tools/qa/targets-parity.mjs).
 */

function al_card(string $code): array
{
    $id = (int) Db::val('SELECT id FROM services WHERE code = ?', [$code]);
    foreach (Catalog::storefront() as $platform) {
        foreach ($platform['sections'] as $section) {
            foreach ($section['services'] as $card) {
                if ($card['id'] === $id) {
                    return $card;
                }
            }
        }
    }
    throw new RuntimeException("no storefront card for {$code}");
}

/** @return array{ref:string,order:array,payment:array} */
function al_place(string $code, string $target): array
{
    static $n = 0;
    return pf_place($code, $target, ['visitor_id' => null, 'session_id' => null], '198.51.100.' . (100 + (++$n % 120)));
}

T::test('account link: Followers and Subscribers cards ask for the profile / channel link, every other card keeps its input', function () {
    pf_setup();
    foreach (['ig-followers-50', 'ig-followers-100k'] as $code) {
        $c = al_card($code);
        T::true($c['link_only'], "$code takes a link");
        T::same('Instagram Profile Link', $c['label']);
        T::same('https://www.instagram.com/username/', $c['placeholder']);
    }
    foreach (['yt-subs-50', 'yt-subs-100k'] as $code) {
        $c = al_card($code);
        T::true($c['link_only'], "$code takes a link");
        T::same('YouTube Channel Link', $c['label']);
        T::same('https://www.youtube.com/@channelname', $c['placeholder']);
    }
    foreach (['ig-blue-tick', 'ig-likes-100', 'ig-views-1k', 'yt-likes-100', 'yt-views-10', 'yt-watch-4000h'] as $code) {
        $c = al_card($code);
        T::false($c['link_only'], "$code is unchanged");
        T::same('Enter username or link', $c['label']);
    }
    T::same('@username or https://instagram.com/username', al_card('ig-blue-tick')['placeholder'], 'Blue Tick still takes a username or a link');
});

T::test('account link: the complete validated link is saved with the order, its history and the admin / customer views', function () {
    pf_setup();
    $p = al_place('ig-followers-100k', 'instagram.com/john.doe?igsh=MWRtN2Fi');
    $o = $p['order'];
    T::same('MANUAL', $o['mode']);
    T::same('https://www.instagram.com/john.doe/', $o['target_url'], 'normalised link');
    T::same('https://www.instagram.com/john.doe/', $o['target'], 'the order shows the complete link, not what was typed');
    T::contains('https://www.instagram.com/john.doe/', (string) Db::val('SELECT note FROM order_status_history WHERE order_id = ?', [$o['id']]));
    T::same(['target_url' => 'https://www.instagram.com/john.doe/'], json_decode((string) Db::val('SELECT meta_json FROM order_status_history WHERE order_id = ?', [$o['id']]), true));

    $d = AdminOrders::detail((int) $o['id']);
    T::same('Instagram Profile Link', $d['order']['target_label']);
    T::true($d['order']['link_only']);
    T::same('https://www.instagram.com/john.doe/', $d['order']['target_url']);
    $row = AdminOrders::list(['q' => 'john.doe'])['rows'][0] ?? null;
    T::same('https://www.instagram.com/john.doe/', $row['target'] ?? null, 'the orders list shows the link and can search it');
    T::same('https://www.instagram.com/john.doe/', $row['target_url'] ?? null);
    T::same('https://www.instagram.com/john.doe/', Orders::customerView($o)['target'], 'the tracking page and "your orders" show the link');

    $y = al_place('yt-subs-100k', 'https://m.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA/videos?si=abc');
    T::same('https://www.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA', $y['order']['target_url']);
    T::same('https://www.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA', $y['order']['target']);
    $d = AdminOrders::detail((int) $y['order']['id']);
    T::same('YouTube Channel Link', $d['order']['target_label']);

    $h = al_place('yt-subs-100k', 'youtube.com/@My.Channel_1/');
    T::same('https://www.youtube.com/@My.Channel_1', $h['order']['target_url']);

    $b = al_place('ig-blue-tick', '@someone');
    T::same('@someone', $b['order']['target'], 'other services keep storing what the customer typed');
    T::same('https://www.instagram.com/someone/', $b['order']['target_url']);
    T::same('Target', AdminOrders::detail((int) $b['order']['id'])['order']['target_label']);
});

T::test('account link: a bare @username / @handle is refused for Followers and Subscribers (server side), and nothing is created', function () {
    pf_setup();
    foreach ([['ig-followers-50', '@john'], ['ig-followers-100k', 'john'], ['yt-subs-50', '@mychannel'], ['yt-subs-100k', 'mychannel']] as [$code, $target]) {
        $e = T::throws(HttpException::class, fn() => al_place($code, $target), 422);
        T::same('invalid_target', $e->errorCode);
        T::true(isset($e->extra['fields']['target']) && $e->extra['fields']['target'] !== '', "field error for $code / $target");
        T::contains('link', strtolower($e->getMessage()), 'the message asks for the link');
    }
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM orders'));
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM payments'));
});

T::test('account link: malformed links, other domains and the wrong kind of link are refused', function () {
    pf_setup();
    $bad = [
        'ig-followers-50' => [
            'https://www.instagram.com/', 'https://www.instagram.com/p/CxYz123AbC/', 'https://www.instagram.com/reel/CxYz123AbC/', 'https://instagram.com.evil.com/john/',
            'https://evil.com/instagram.com/john', 'https://www.facebook.com/john', 'https://www.youtube.com/@john', 'https://user:pw@instagram.com/john', 'ftp://instagram.com/john',
            'javascript:alert(1)', 'https:/instagram.com/john', 'https://www.instagram.com/john smith/', 'https://www.instagram.com/' . str_repeat('a', 31) . '/',
        ],
        'yt-subs-50' => [
            'https://www.youtube.com/', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/channel/UCshort',
            'https://youtube.com.evil.com/@john', 'https://evil.com/@john', 'https://www.instagram.com/john/', 'https://user@youtube.com/@john', 'javascript:alert(1)',
        ],
    ];
    foreach ($bad as $code => $targets) {
        foreach ($targets as $t) {
            $e = T::throws(HttpException::class, fn() => al_place($code, $t), 422);
            T::same('invalid_target', $e->errorCode, "$code ← " . json_encode($t));
        }
    }
    T::same(0, (int) Db::val('SELECT COUNT(*) FROM orders'));
});

T::test('account link: AUTO orders send the validated account link to the SMM panel — Instagram and YouTube', function () {
    ['gid' => $gid, 'pid' => $pid] = ff_setup();
    T::makeAuto('yt-subs-50', '2001', 100, $pid);
    $i = ff_paid($gid, 'ig-followers-50', 'www.instagram.com/john.doe/?igsh=abc#top');
    $y = ff_paid($gid, 'yt-subs-50', 'https://m.youtube.com/@My.Channel?si=xyz');
    $c = ff_paid($gid, 'yt-subs-50', 'https://youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA/about');
    foreach ([$i, $y, $c] as $o) {
        T::same('SUBMITTED', $o['status']);
    }
    $links = array_column(ff_smm_adds(), 'link');
    T::same(['https://www.instagram.com/john.doe/', 'https://www.youtube.com/@My.Channel', 'https://www.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA'], $links);
    foreach ([$i, $y, $c] as $o) {
        $req = (string) Db::val("SELECT request_json FROM smm_transactions WHERE order_id = ? AND action = 'add'", [$o['id']]);
        T::contains((string) $o['target_url'], str_replace('\\/', '/', $req), 'the provider call log keeps the link');
    }
});
