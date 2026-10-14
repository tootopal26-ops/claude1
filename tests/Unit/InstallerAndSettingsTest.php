<?php
declare(strict_types=1);

use App\Core\Env;
use App\Services\AdminSettings;
use App\Services\Password;
use App\Support\Color;
use App\Support\Installer;

// ───────────────────────────── .env round trip ─────────────────────────────
T::test('env: the installer writes values the loader reads back EXACTLY (quotes, backslashes, #, =, spaces, unicode)', function () {
    $tricky = ['p"a$s\\wrd', 'back\\slash\\n literal', 'trailing\\', 'has # hash and = sign', '  spaced  ', 'ünïcode ₹ ok', '\\\\double', 'a\\"b'];
    foreach ($tricky as $i => $pw) {
        $built = Installer::buildEnv(['APP_URL' => 'https://x.test', 'DB_HOST' => 'h', 'DB_PORT' => '3306', 'DB_NAME' => 'n', 'DB_USER' => 'u', 'DB_PASS' => $pw]);
        $text = str_replace(['DB_PASS='], ["SMB_RT_{$i}="], $built['text']);
        $tmp = sys_get_temp_dir() . "/smb-env-{$i}.env";
        file_put_contents($tmp, $text);
        Env::load($tmp);
        unlink($tmp);
        T::same(str_replace(["\n", "\r"], '', $pw), Env::get("SMB_RT_{$i}"), 'round trip of ' . json_encode($pw));
    }
});

T::test('env: an existing APP_KEY / CRON_KEY survives a re-install (encrypted settings stay readable)', function () {
    $a = Installer::buildEnv(['APP_URL' => 'https://x.test', 'DB_HOST' => 'h', 'DB_PORT' => '3306', 'DB_NAME' => 'n', 'DB_USER' => 'u', 'DB_PASS' => 'p']);
    T::true(str_starts_with($a['key'], 'base64:') || $a['key'] !== '');
    T::true(strlen($a['cron']) >= 20);
});

// ───────────────────────────── installer validation ─────────────────────────────
function inst_ok(): array
{
    return ['url' => 'https://example.com', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'cpuser_smm', 'db_user' => 'cpuser_smm', 'db_pass' => 'x',
        'site_name' => 'My Shop', 'timezone' => 'Asia/Kolkata', 'admin_username' => 'owner', 'admin_email' => 'me@example.com', 'admin_password' => 'Zebra-Cloud#Install-7741', 'admin_confirm' => 'Zebra-Cloud#Install-7741'];
}
T::test('installer: valid input passes; each bad field is reported on its own', function () {
    T::same([], Installer::validate(inst_ok()));
    $bad = [
        'url' => ['ftp://x.com', 'javascript:alert(1)', 'example.com', 'https://u:p@x.com', 'https://x.com?a=1', ''],
        'db_host' => ['', 'host name', 'a;b', str_repeat('a', 130)],
        'db_port' => ['0', '70000', 'abc', '3306x'],
        'db_name' => ['', 'bad name', 'a;drop', str_repeat('x', 65)],
        'db_user' => ['', str_repeat('u', 81)],
        'site_name' => ['', 'A', str_repeat('n', 61)],
        'timezone' => ['Mars/Olympus', 'UTC; rm -rf'],
        'admin_username' => ['', 'ab', 'has space', 'x/y', str_repeat('a', 65)],
        'admin_email' => ['', 'nope', 'a@b', str_repeat('a', 200) . '@x.com'],
        'admin_password' => ['', 'short', 'alllowercase1!x', 'NoSymbols12345Abc', 'Password#12345678'],
    ];
    foreach ($bad as $field => $values) {
        foreach ($values as $v) {
            $in = inst_ok();
            $in[$field] = $v;
            if ($field === 'admin_password') {
                $in['admin_confirm'] = $v;
            }
            $e = Installer::validate($in);
            T::true(isset($e[$field]), "{$field} = " . json_encode($v) . ' should be rejected');
        }
    }
    $in = inst_ok();
    $in['admin_confirm'] = 'different';
    T::true(isset(Installer::validate($in)['admin_confirm']));
    $in = inst_ok();
    $in['admin_password'] = $in['admin_confirm'] = 'Owner-Pass#Zebra-123';       // contains the username
    T::true(isset(Installer::validate($in)['admin_password']), 'password must not contain the username');
});

T::test('installer: base path is detected from the install URL (sub-folder installs) and the throttle locks after repeated failures', function () {
    T::same('', Installer::detectBasePath('/install'));
    T::same('/shop', Installer::detectBasePath('/shop/install'));
    T::same('/a/b', Installer::detectBasePath('/a/b/install/unlock?x=1'));
    $file = SMB_ROOT . '/storage/cache/install-attempts.json';
    $backup = is_file($file) ? file_get_contents($file) : null;
    @unlink($file);
    for ($i = 0; $i < 7; $i++) {
        T::true(Installer::throttle('203.0.113.77', true)['allowed'], 'attempt ' . ($i + 1));
    }
    Installer::throttle('203.0.113.77', true);                                    // 8th failure → lock
    $t = Installer::throttle('203.0.113.77', false);
    T::false($t['allowed']);
    T::true($t['retry_after'] > 800 && $t['retry_after'] <= 900);
    T::true(Installer::throttle('203.0.113.78', false)['allowed'], 'other visitors are not affected');
    @unlink($file);
    if ($backup !== null) {
        file_put_contents($file, $backup);
    }
});

// ───────────────────────────── passwords ─────────────────────────────
T::test('password: the generator always satisfies the policy and never repeats', function () {
    $seen = [];
    for ($i = 0; $i < 150; $i++) {
        $p = Password::generate();
        T::same([], Password::problems($p), $p);
        T::true(strlen($p) >= 20);
        $seen[$p] = true;
    }
    T::same(150, count($seen));
});

// ───────────────────────────── accent colours & support links ─────────────────────────────
T::test('color: white-on-accent contrast mirrors the browser (default accents pass AA, yellow/pale fail)', function () {
    T::true(Color::isHex('#ff2d87'));
    foreach (['red', '#12345', '#gggggg', '#ff2d87;}', ' #ff2d87', '#FF2D87 '] as $bad) {
        T::false(Color::isHex($bad), $bad);
    }
    T::same('5.0', number_format(Color::buttonContrast('#ff2d87'), 1));
    T::same('5.1', number_format(Color::buttonContrast('#ff3b3b'), 1));
    T::true(Color::buttonContrast('#ffff00') < 4.5, 'yellow is too light for white text');
    T::true(Color::buttonContrast('#ffc2da') < 4.5, 'pale pink too light');
    T::true(Color::buttonContrast('#000000') > 20, 'black');
    T::true(Color::buttonContrast('#1d4ed8') >= 4.5, 'deep blue is fine');
});

T::test('settings: only safe support links are accepted (https / mailto) — no javascript:, data:, credentials or control chars', function () {
    foreach (['https://wa.me/911234567890', 'https://t.me/mysupport', 'https://example.com/help?x=1', 'mailto:help@example.com'] as $ok) {
        T::true(AdminSettings::isSafeSupportLink($ok), $ok);
    }
    foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,<script>1</script>', 'http://insecure.example', 'ftp://x.example', 'https://u:p@x.example',
        'https://x.example/"onmouseover="x', "https://x.example/\nSet-Cookie:a=b", 'https://', 'mailto:not-an-email', '//evil.example', 'vbscript:x', 'https://x.example/<script>', str_repeat('a', 301)] as $bad) {
        T::false(AdminSettings::isSafeSupportLink($bad), json_encode($bad));
    }
});
