<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Services\Assets;

/** A solid-colour PNG of the given size (compresses to a few KB however large it is). */
function as_png(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int) imagecolorallocate($im, 255, 20, 90));
    ob_start();
    imagepng($im, null, 9);
    $out = (string) ob_get_clean();
    imagedestroy($im);
    return $out;
}

T::test('assets: a valid image is re-encoded, shrunk to the kind\'s maximum and stored under a random, owned name', function () {
    $r = Assets::fromBytes(as_png(900, 600), 'platform_logo');
    try {
        T::same(256, $r['width']);
        T::same(171, $r['height']);                       // aspect ratio kept: 600 × 256 / 900
        T::true(Assets::isOwnPath($r['path']), $r['path']);
        $file = SMB_PUBLIC . '/uploads/' . $r['path'];
        T::true(is_file($file));
        $info = getimagesize($file);
        T::same([256, 171], [$info[0], $info[1]]);
        T::true(in_array($info['mime'], ['image/webp', 'image/png'], true), $info['mime']);
    } finally {
        Assets::delete($r['path']);
    }
    T::false(is_file(SMB_PUBLIC . '/uploads/' . $r['path']), 'delete removes the file');
});

T::test('assets: the CONTENT decides — SVG, scripts, text and broken or tiny images are refused', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><script>alert(1)</script></svg>';
    foreach ([$svg, '<?php echo "PWNED";', 'just some text', '', "GIF89a\x01\x00\x01\x00", as_png(8, 8), substr(as_png(64, 64), 0, 60)] as $i => $bytes) {
        T::throws(HttpException::class, fn() => Assets::fromBytes($bytes, 'site_logo'), $i === 3 ? 413 : 422);
    }
    T::throws(InvalidArgumentException::class, fn() => Assets::fromBytes(as_png(64, 64), 'something_else'));
});

T::test('assets: only files this class created can be deleted (no path traversal)', function () {
    foreach (['../.env', '../../app/bootstrap.php', 'branding/../../.env', 'branding/x.webp', 'branding/' . str_repeat('a', 20) . '.php', '/etc/passwd', null] as $bad) {
        T::false(Assets::isOwnPath($bad), (string) $bad);
    }
    T::true(Assets::isOwnPath('branding/' . str_repeat('a1', 10) . '.webp'));
});

T::test('assets: an image the server has no memory to decode is refused politely instead of dying with a fatal error', function () {
    $png = as_png(3000, 3000);                            // 9 megapixels ≈ 47 MB once decoded
    $old = (string) ini_get('memory_limit');
    try {
        ini_set('memory_limit', (string) ((int) ceil(memory_get_usage(true) / 1048576) + 20) . 'M');
        $e = T::throws(HttpException::class, fn() => Assets::fromBytes($png, 'site_logo'), 422);
        T::contains('too large for this server', $e->getMessage());
    } finally {
        ini_set('memory_limit', $old);
    }
    // with enough memory the very same image is accepted
    $r = Assets::fromBytes($png, 'site_logo');
    Assets::delete($r['path']);
    T::same(512, $r['width']);
});
