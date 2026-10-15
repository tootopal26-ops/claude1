<?php
declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Db;
use App\Services\Password;
use App\Support\Money;
use App\Support\NetGuard;
use App\Support\Profit;
use App\Support\Qr;
use App\Support\Status;
use App\Support\Tz;

// ───────────────────────────── money ─────────────────────────────
T::test('money: parses rupee strings exactly (no float drift)', function () {
    T::same(108, Money::parseRupees('1.08'));
    T::same(108, Money::parseRupees(1.08));          // a JSON float must not become 107.99999…
    T::same(10, Money::parseRupees('0.1'));
    T::same(500, Money::parseRupees('5'));
    T::same(19900, Money::parseRupees('199.00'));
    T::same(19900, Money::parseRupees('199.000'));   // trailing zeros are harmless
    T::same(1, Money::parseRupees('0.01'));
    T::same(1000099, Money::parseRupees(10000.99));
});
T::test('money: rejects bad amounts', function () {
    foreach (['-1', '1.005', '1e3', 'abc', '', '1,000', '0x10', '1.', '.5', '12345678901'] as $bad) {
        T::throws(InvalidArgumentException::class, fn() => Money::parseRupees($bad));
    }
    T::throws(InvalidArgumentException::class, fn() => Money::parseRupees(null));
    T::throws(InvalidArgumentException::class, fn() => Money::parseRupees(NAN));
});
T::test('money: formatting incl. Indian grouping', function () {
    T::same('10.80', Money::plain(1080));
    T::same('5.00', Money::plain(500));
    T::same('₹5', Money::inr(500));
    T::same('₹10.80', Money::inr(1080));
    T::same('₹1,000', Money::inr(100000));
    T::same('₹1,23,456.50', Money::inr(12345650));
    T::same('₹1,00,00,000', Money::inr(1000000000));
    T::same('-₹592', Money::inr(-59200));
    T::same('₹0.00', Money::inr(0, true));
});
T::test('money: integer percentage / rounding', function () {
    T::same(592, Money::pctTenths(59200, 100000));
    T::same('59.2', Money::pct(59200, 100000));
    T::same('0.0', Money::pct(5, 0));
    T::same(3, Money::mulDiv(5, 1, 3) + 1);          // 1.67 → 2 ; +1
    T::same(2, Money::mulDiv(5, 1, 3));
    T::same(-2, Money::mulDiv(-5, 1, 3));
    T::same(1, Money::mulDiv(1, 1, 2));              // half rounds away from zero
});
T::test('money: provider charge (rupees) → paise — INR only, no exchange rate', function () {
    T::same(278190, Money::parseDecimalMicros('0.27819'));
    T::same(28, Money::rupeeMicrosToPaise(278190), '₹0.27819 → 28 paise (half up)');
    T::same(2323, Money::rupeeMicrosToPaise((int) Money::parseDecimalMicros('23.23')));
    T::same(100, Money::rupeeMicrosToPaise(1_000_000));
    T::same(0, Money::rupeeMicrosToPaise(0));
    T::same(1, Money::rupeeMicrosToPaise(5_000), '₹0.005 → 1 paise (half rounds up)');
    T::same(null, Money::parseDecimalMicros('abc'));
    T::same(null, Money::rupeeMicrosToPaise(-1));
    T::true(Money::rupeeMicrosToPaise(PHP_INT_MAX) > 0, 'no overflow');
});

// ───────────────────────────── profit ─────────────────────────────
T::test('profit: MANUAL counts 100% as profit, AUTO subtracts provider cost', function () {
    $manual = Profit::forOrder(['mode' => 'MANUAL', 'amount_paise' => 9900, 'refunded_paise' => 0, 'provider_cost_paise' => 5000]);
    T::same(['revenue' => 9900, 'cost' => 0, 'gross' => 9900], $manual, 'manual ignores provider cost');
    $auto = Profit::forOrder(['mode' => 'AUTO', 'amount_paise' => 9900, 'refunded_paise' => 0, 'provider_cost_paise' => 3000]);
    T::same(['revenue' => 9900, 'cost' => 3000, 'gross' => 6900], $auto);
    $refunded = Profit::forOrder(['mode' => 'AUTO', 'amount_paise' => 9900, 'refunded_paise' => 9900, 'provider_cost_paise' => 3000]);
    T::same(-3000, $refunded['gross'], 'a refunded AUTO order still carries the provider cost it incurred');
});
T::test('profit: the spec example (₹1000 − ₹300 SMM − ₹108 ads = ₹592, 59.2%)', function () {
    $b = Profit::breakdown(100000, 30000, 10800);
    T::same(59200, $b['net']);
    T::same(70000, $b['gross']);
    T::same('59.2', $b['margin']);
});
T::test('profit: ad spend example (10 qualified visitors × ₹1.08 = ₹10.80)', function () {
    T::same('10.80', Money::plain(10 * Money::parseRupees('1.08')));
});
T::test('profit: service preview', function () {
    $p = Profit::servicePreview(9900, 2500, 'AUTO', 2700);
    T::same(7400, $p['gross']);
    T::same(4700, $p['net']);
    T::same('47.5', $p['margin']);
    $m = Profit::servicePreview(9900, 2500, 'MANUAL', 2700);
    T::same(0, $m['provider_cost'], 'manual has no provider cost');
    T::same(9900, $m['gross']);
});

// ───────────────────────────── state machine ─────────────────────────────
T::test('status: allowed and forbidden transitions', function () {
    T::true(Status::canTransition('PENDING_PAYMENT', 'PAID'));
    T::true(Status::canTransition('PAID', 'PROCESSING'));
    T::true(Status::canTransition('MANUAL_PENDING', 'MANUAL_APPROVED'));
    T::true(Status::canTransition('EXPIRED', 'PAID'), 'a late payment may still land');
    T::false(Status::canTransition('PENDING_PAYMENT', 'COMPLETED'));
    T::false(Status::canTransition('COMPLETED', 'PAID'));
    T::false(Status::canTransition('REFUNDED', 'PAID'));
    T::false(Status::canTransition('CANCELLED', 'MANUAL_APPROVED'));
    T::same(13, count(Status::ORDER_ALL));
    foreach (Status::ORDER_ALL as $s) {
        foreach (Status::nextStatuses($s) as $n) {
            T::true(in_array($n, Status::ORDER_ALL, true), "$s → $n must be a known status");
        }
    }
});
T::test('status: provider status mapping', function () {
    T::same('SUBMITTED', Status::fromProviderStatus('Pending'));
    T::same('IN_PROGRESS', Status::fromProviderStatus('In progress'));
    T::same('COMPLETED', Status::fromProviderStatus('Completed'));
    T::same('PARTIAL', Status::fromProviderStatus('Partial'));
    T::same('CANCELLED', Status::fromProviderStatus('Canceled'));
    T::same('FAILED', Status::fromProviderStatus('Fail'));
    T::same(null, Status::fromProviderStatus('???'));
});

// ───────────────────────────── passwords / crypto ─────────────────────────────
T::test('password: policy', function () {
    T::same([], Password::problems('Tr0ub4dor&Horse-Staple'));
    T::true(count(Password::problems('short1A!')) > 0);
    T::true(count(Password::problems('alllowercase123!!')) > 0);
    T::true(count(Password::problems('NoDigitsHere!!!!')) > 0);
    T::true(count(Password::problems('Password123!!x')) > 0, 'common word');
    T::true(count(Password::problems('Xk9!mQ2#vL8@pwnadmin', ['username' => 'pwnadmin'])) > 0, 'contains username');
});
T::test('password: hash/verify round trip, long passphrases, rehash', function () {
    $pw = 'Correct-Horse-Battery-Staple-9!' . str_repeat('x', 100);
    $h = Password::hash($pw);
    T::true(Password::verify($pw, $h));
    T::false(Password::verify($pw . 'y', $h), 'a long password must not be silently truncated');
    T::false(Password::verify('wrong', $h));
    T::true(strlen($h) > 50 && !str_contains($h, $pw));
});
T::test('crypto: encrypt/decrypt, tamper detection, masking', function () {
    $c = Crypto::encrypt('super-secret-api-key');
    T::true(str_starts_with($c, 'v1:'));
    T::false(str_contains($c, 'super-secret'));
    T::same('super-secret-api-key', Crypto::decrypt($c));
    T::same(null, Crypto::decrypt(substr($c, 0, -4) . 'AAAA'), 'tampered ciphertext must not decrypt');
    T::same(null, Crypto::decrypt('garbage'));
    T::true(Crypto::encrypt('x') !== Crypto::encrypt('x'), 'random nonce');
    T::same(['a' => 1], Crypto::decryptJson(Crypto::encryptJson(['a' => 1])));
    T::same('••••••••3456', Crypto::mask('abcdefgh123456'));
    T::same(32, strlen(Crypto::token(16)));
});

// ───────────────────────────── SSRF guard ─────────────────────────────
T::test('netguard: private/internal addresses are not public', function () {
    foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.0.9', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fd00::1'] as $ip) {
        T::false(NetGuard::isPublicIp($ip), $ip);
    }
    foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111'] as $ip) {
        T::true(NetGuard::isPublicIp($ip), $ip);
    }
});
T::test('netguard: production rules reject http / private hosts / credentials', function () {
    putenv('ALLOW_PRIVATE_API_HOSTS=false');
    App\Core\Env::set('ALLOW_PRIVATE_API_HOSTS', 'false');
    try {
        T::throws(InvalidArgumentException::class, fn() => NetGuard::assertSafeUrl('http://example.com'));
        T::throws(InvalidArgumentException::class, fn() => NetGuard::assertSafeUrl('https://127.0.0.1/api'));
        T::throws(InvalidArgumentException::class, fn() => NetGuard::assertSafeUrl('https://localhost'));
        T::throws(InvalidArgumentException::class, fn() => NetGuard::assertSafeUrl('https://169.254.169.254/latest/meta-data'));
        T::throws(InvalidArgumentException::class, fn() => NetGuard::assertSafeUrl('https://user:pw@8.8.8.8/'));
        T::throws(InvalidArgumentException::class, fn() => NetGuard::assertSafeUrl('ftp://8.8.8.8/'));
        T::throws(InvalidArgumentException::class, fn() => NetGuard::assertSafeUrl('not a url'));
        T::same('https://8.8.8.8/api', NetGuard::assertSafeUrl('https://8.8.8.8/api/'));
    } finally {
        putenv('ALLOW_PRIVATE_API_HOSTS=true');
        App\Core\Env::set('ALLOW_PRIVATE_API_HOSTS', 'true');
    }
});

// ───────────────────────────── time zone ─────────────────────────────
T::test('tz: business-day maths (Asia/Kolkata = UTC+5:30)', function () {
    Tz::set('Asia/Kolkata');
    // 2026-03-10 20:00 UTC is already 2026-03-11 01:30 in India
    T::same('2026-03-11', Tz::dayOf(gmmktime(20, 0, 0, 3, 10, 2026)));
    T::same('2026-03-10', Tz::dayOf(gmmktime(18, 29, 0, 3, 10, 2026)));
    T::same(19800, Tz::offsetSeconds());
    T::same('2026-03-01', Tz::addDays('2026-03-02', -1));
    T::same('2026-03-01', Tz::addDays('2026-02-28', 1));
    T::same(gmmktime(18, 30, 0, 3, 9, 2026), Tz::dayStart('2026-03-10'));
    T::true(Tz::isDay('2026-02-28'));
    T::false(Tz::isDay('2026-02-30'));
    Tz::reset();
});

// ───────────────────────────── QR ─────────────────────────────
T::test('qr: matrix structure (finder patterns, size by version)', function () {
    $m = Qr::matrix('https://pay.example.com/p/ABC', 'M');
    T::same(true, count($m) >= 21 && (count($m) - 17) % 4 === 0);
    $n = count($m);
    foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r, $c]) {
        T::true($m[$r][$c] && $m[$r][$c + 6] && $m[$r + 6][$c] && $m[$r + 6][$c + 6], 'finder corners dark');
        T::true(!$m[$r + 1][$c + 1] && $m[$r + 3][$c + 3], 'finder ring light, centre dark');
    }
    T::throws(InvalidArgumentException::class, fn() => Qr::matrix(''));
    T::throws(InvalidArgumentException::class, fn() => Qr::matrix(str_repeat('x', 5000)));
});
T::test('qr: PNG decodes back to the payload (independent decoders: jsQR via node, ZXing via python when installed)', function () {
    $root = dirname(__DIR__, 2);
    $haveNode = is_file($root . '/node_modules/jsqr/package.json') && trim((string) shell_exec('node -v 2>/dev/null')) !== '';
    $haveZxing = trim((string) shell_exec('python3 -c "import zxingcpp; print(1)" 2>/dev/null')) === '1';
    if (!$haveNode && !$haveZxing) {
        echo "      (skipped: neither node + jsqr (npm install) nor python zxingcpp is available)\n";
        return;
    }
    // sizes chosen to cover small versions, the multi-block layouts, the version-info blocks (v7+) and the largest versions
    // jsQR is reliable up to roughly version 22 (it fails on some huge symbols that ZXing reads fine), so the big ones need ZXing
    $payloads = ['A', 'https://pay.example.com/pay/ABCDEF123456', str_repeat('Alpha UPI ', 20), 'upi://pay?pa=a@b&am=5.00&cu=INR&tn=Order%20SMB-ABCDEFGH', str_repeat('x', 300), random_bytes_ascii(700)];
    if ($haveZxing) {
        array_push($payloads, random_bytes_ascii(1091), random_bytes_ascii(1400), random_bytes_ascii(2200));
    }
    $dir = sys_get_temp_dir() . '/smb-qr-' . getmypid();
    @mkdir($dir);
    $files = [];
    $cases = [];
    foreach ($payloads as $i => $payload) {
        foreach (['L', 'M', 'Q', 'H'] as $ecc) {
            if (strlen($payload) > 1200 && in_array($ecc, ['Q', 'H'], true)) {
                continue;                      // beyond the capacity of version 40 at that level
            }
            $f = "$dir/$i-$ecc.png";
            file_put_contents($f, Qr::png($payload, 6, 4, $ecc));
            $files[] = $f;
            $cases[] = $payload;
        }
    }
    if ($haveNode) {
        $out = json_decode((string) shell_exec('node ' . escapeshellarg($root . '/tools/qa/qr-decode.mjs') . ' ' . implode(' ', array_map('escapeshellarg', $files))), true);
        T::same(count($cases), is_array($out) ? count($out) : -1, 'decoder answered for every image');
        foreach ($cases as $i => $payload) {
            if (strlen($payload) <= 800) {
                T::same($payload, $out[$i] ?? null, 'jsQR decodes ' . basename($files[$i]) . ' (' . strlen($payload) . ' bytes)');
            }
        }
    }
    if ($haveZxing) {
        foreach ($cases as $i => $payload) {
            $out = shell_exec('python3 -c "import zxingcpp,sys; from PIL import Image; r=zxingcpp.read_barcodes(Image.open(sys.argv[1]).convert(\'L\')); print(r[0].text if r else \'NONE\')" ' . escapeshellarg($files[$i]));
            T::same($payload, rtrim((string) $out, "\r\n"), 'zxing decodes ' . basename($files[$i]));
        }
    }
});
function random_bytes_ascii(int $n): string
{
    $s = '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~:/?#[]@!$&\'()*+,;=%';
    for ($i = 0; $i < $n; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $s;
}

// ───────────────────────────── database session ─────────────────────────────
T::test('db: UNSIGNED money columns subtract to a NEGATIVE number (a loss) instead of an "out of range" error — on MySQL 8 and MariaDB alike', function () {
    T::same(-1, (int) Db::val('SELECT CAST(1 AS UNSIGNED) - CAST(2 AS UNSIGNED)'));
    Db::exec('DROP TEMPORARY TABLE IF EXISTS qa_unsigned_money');
    Db::exec('CREATE TEMPORARY TABLE qa_unsigned_money (amount_paise INT UNSIGNED NOT NULL, refunded_paise INT UNSIGNED NOT NULL, provider_cost_paise INT UNSIGNED NOT NULL, mode VARCHAR(8) NOT NULL)');
    Db::exec("INSERT INTO qa_unsigned_money VALUES (500, 500, 100, 'AUTO'), (500, 0, 700, 'AUTO'), (500, 0, 700, 'MANUAL')");
    // the exact expressions the dashboard sums: a refunded AUTO order still carries its provider cost, a pack sold below cost loses money
    $gross = Db::col('SELECT ' . Profit::SQL_GROSS . ' FROM qa_unsigned_money o ORDER BY o.refunded_paise DESC, o.mode');
    T::same([-100, -200, 500], array_map('intval', $gross));
    T::same(200, (int) Db::val('SELECT SUM(' . Profit::SQL_GROSS . ') FROM qa_unsigned_money o'));       // -100 - 200 + 500
    // …while the columns themselves still refuse a negative value
    T::throws(PDOException::class, fn() => Db::exec('UPDATE qa_unsigned_money SET amount_paise = amount_paise - 600 WHERE provider_cost_paise = 100'));
    Db::exec('DROP TEMPORARY TABLE qa_unsigned_money');
});
