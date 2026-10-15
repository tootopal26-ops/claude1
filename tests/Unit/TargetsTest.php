<?php
declare(strict_types=1);

use App\Support\Targets;

// The JS implementation (resources/js/targets.js) is verified against the SAME file by tools/qa/targets-parity.mjs.
T::test('targets: every fixture (accepts, rejects and normalised URL)', function () {
    $cases = json_decode((string) file_get_contents(SMB_ROOT . '/tests/fixtures/targets.json'), true);
    T::true(count($cases) >= 200, 'fixture file present');
    foreach ($cases as $c) {
        $r = Targets::validate($c['platform'], $c['kind'], $c['input'], (bool) ($c['link_only'] ?? false));
        $label = $c['platform'] . '/' . $c['kind'] . (!empty($c['link_only']) ? ' (link only)' : '') . ' ' . json_encode($c['input'], JSON_UNESCAPED_UNICODE);
        T::same($c['ok'], $r['ok'], $label);
        if ($c['ok']) {
            T::same($c['url'], $r['url'], $label);
            T::same($c['display'], $r['display'], $label);
        } else {
            T::true(isset($r['error']) && $r['error'] !== '', "error message for $label");
        }
    }
});
T::test('targets: never returns a URL on a foreign host', function () {
    $cases = json_decode((string) file_get_contents(SMB_ROOT . '/tests/fixtures/targets.json'), true);
    foreach ($cases as $c) {
        if ($c['ok']) {
            $host = parse_url($c['url'], PHP_URL_HOST);
            T::true(in_array($host, ['www.instagram.com', 'www.youtube.com'], true), 'normalised host for ' . $c['input']);
            T::true(str_starts_with($c['url'], 'https://'));
        }
    }
});
T::test('targets: unknown platform is refused', function () {
    T::false(Targets::validate('tiktok', 'profile', '@x')['ok']);
});

// ── Instagram Followers / YouTube Subscribers take the account LINK ──
T::test('targets: only followers / subscribers are link-only (Blue Tick, likes, views and watch time keep their input)', function () {
    T::true(Targets::requiresLink('instagram', 'profile', 'followers'));
    T::true(Targets::requiresLink('youtube', 'channel', 'subscribers'));
    T::false(Targets::requiresLink('instagram', 'profile', 'verification'), 'Blue Tick keeps "username or link"');
    T::false(Targets::requiresLink('instagram', 'post', 'likes'));
    T::false(Targets::requiresLink('instagram', 'post', 'views'));
    T::false(Targets::requiresLink('youtube', 'video', 'views'));
    T::false(Targets::requiresLink('youtube', 'video', 'watch_time'));
    T::false(Targets::requiresLink('instagram', 'channel', 'followers'), 'the kind must fit the platform');
    T::false(Targets::requiresLink('youtube', 'profile', 'subscribers'), 'the kind must fit the platform');
    T::false(Targets::requiresLink('tiktok', 'profile', 'followers'));
});
T::test('targets: link-only labels and placeholders are exactly the requested ones', function () {
    T::same('Instagram Profile Link', Targets::label('instagram', true));
    T::same('YouTube Channel Link', Targets::label('youtube', true));
    T::same('https://www.instagram.com/username/', Targets::placeholder('instagram', 'profile', true));
    T::same('https://www.youtube.com/@channelname', Targets::placeholder('youtube', 'channel', true));
    T::same('Enter username or link', Targets::label('instagram', false), 'other services keep their label');
    T::same('@username or https://instagram.com/username', Targets::placeholder('instagram', 'profile'), 'other services keep their placeholder');
    T::same('Enter your Instagram @username or profile link.', Targets::hint('instagram', 'profile'));
    T::false(str_contains(strtolower(Targets::hint('instagram', 'profile', true)), 'username'), 'the link-only hint does not ask for a username');
    T::false(str_contains(strtolower(Targets::hint('youtube', 'channel', true)), 'handle'), 'the link-only hint does not ask for a handle');
});
T::test('targets: a bare username / handle is refused with a message that asks for the link', function () {
    foreach ([['instagram', 'profile', '@someone'], ['instagram', 'profile', 'someone'], ['youtube', 'channel', '@somechannel'], ['youtube', 'channel', 'somechannel']] as [$pl, $kind, $in]) {
        $r = Targets::validate($pl, $kind, $in, true);
        T::false($r['ok'], "$in must not pass as a link");
        T::true(str_contains($r['error'], 'https://www.'), 'the message shows the link format');
        T::true(Targets::validate($pl, $kind, $in, false)['ok'], "$in is still fine for services that take a username (unchanged)");
    }
});
T::test('targets: the empty-field message of a link-only service names the link, not a username', function () {
    T::same(Targets::hint('instagram', 'profile', true), Targets::validate('instagram', 'profile', '  ', true)['error']);
    T::same(Targets::hint('youtube', 'channel', true), Targets::validate('youtube', 'channel', '', true)['error']);
});
