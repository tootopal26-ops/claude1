<?php
declare(strict_types=1);

use App\Support\Targets;

// The JS implementation (resources/js/targets.js) is verified against the SAME file by tools/qa/targets-parity.mjs.
T::test('targets: every fixture (accepts, rejects and normalised URL)', function () {
    $cases = json_decode((string) file_get_contents(SMB_ROOT . '/tests/fixtures/targets.json'), true);
    T::true(count($cases) >= 100, 'fixture file present');
    foreach ($cases as $c) {
        $r = Targets::validate($c['platform'], $c['kind'], $c['input']);
        $label = $c['platform'] . '/' . $c['kind'] . ' ' . json_encode($c['input'], JSON_UNESCAPED_UNICODE);
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
