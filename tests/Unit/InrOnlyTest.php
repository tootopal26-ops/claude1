<?php
declare(strict_types=1);

/**
 * The shop works in INR only. These checks read the shipped source (PHP, views, JS, CSS, SQL, the icon sprite and the compiled
 * bundles) and fail if a USD / dollar option, a currency picker or an exchange-rate setting ever comes back. (Attack payloads in the
 * QA tooling, which try to smuggle a currency into a checkout, live in tools/ and tests/ and are not shipped.)
 */

/** @return list<string> */
function inr_files(): array
{
    $out = [];
    foreach (['app', 'config', 'database', 'resources', 'public'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SMB_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = (string) $f;
            if (!$f->isFile() || !preg_match('/\.(php|js|css|svg|sql|html|json|webmanifest)$/i', $path)) {
                continue;
            }
            if (str_contains($path, '/public/uploads/') || str_contains($path, '/node_modules/') || str_contains($path, '/Vendor/')) {
                continue;
            }
            $out[] = $path;
        }
    }
    return $out;
}

T::test('inr only: no USD / dollar / US$ / currency picker anywhere in the shipped source', function () {
    $files = inr_files();
    T::true(count($files) > 100, 'the scan covers the whole project (' . count($files) . ' files)');
    $bad = [];
    foreach ($files as $path) {
        $src = (string) file_get_contents($path);
        $res = ['/\bUSD\b/', '/\bUS\$/', '/dollar/i', '/\bEUR\b/', '/\bGBP\b/', '/rate_to_inr(?!_micros)/', '/Rupees per 1\b/i', '/cur-list/'];
        if (preg_match('#/(resources/js|public/assets/js|app/Views)/#', $path)) {
            $res[] = '/exchange[ -]rate/i';   // no exchange-rate setting or hint in any screen (a code comment saying "there is none" is fine)
        }
        foreach ($res as $re) {
            if (preg_match($re, $src, $m)) {
                $bad[] = str_replace(SMB_ROOT . '/', '', $path) . ' ← ' . $m[0];
            }
        }
    }
    T::same([], $bad, 'forbidden currency references: ' . implode('; ', $bad));
});

T::test('inr only: every price the storefront renders is in rupees', function () {
    pf_setup();
    $html = \App\Core\View::render('layouts/site', ['content' => '', 'site' => \App\Services\Settings::site(), 'title' => 'x', 'page' => 'home']);
    T::false((bool) preg_match('/\bUSD\b|US\$|\$\s?\d/', $html), 'no dollar price in the layout');
    $prices = [];
    foreach (\App\Services\Catalog::storefront() as $platform) {
        foreach ($platform['sections'] as $section) {
            foreach ($section['services'] as $card) {
                $prices[] = $card['price'];
                T::true((bool) preg_match('/^₹\d[\d,]*(\.\d\d)?$/u', $card['price']), 'a ₹ price: ' . $card['price']);
            }
        }
    }
    T::true(count($prices) >= 10, 'prices were checked');
    T::same('₹5', \App\Support\Money::inr(500));
    T::same('₹99', \App\Support\Money::inr(9900));
    T::same('₹1,234.50', \App\Support\Money::inr(123450));
});

T::test('inr only: database defaults are INR (provider row, payments) and no migration introduces another currency', function () {
    $default = static fn(string $table, string $column): string => trim((string) \App\Core\Db::val('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]), "'");   // MariaDB quotes it, MySQL does not
    T::same('INR', $default('smm_providers', 'currency'));
    T::same('INR', $default('payments', 'currency'));
    T::same('1000000', $default('smm_providers', 'rate_to_inr_micros'));
    foreach (glob(SMB_ROOT . '/database/migrations/*.sql') ?: [] as $m) {
        T::false((bool) preg_match('/DEFAULT\s+\'(?!INR\')[A-Z]{3}\'/', (string) file_get_contents($m)), basename($m) . ' sets no non-INR default');
    }
    // a freshly inserted provider and payment are INR without being told
    \App\Core\Db::exec('DELETE FROM smm_providers');
    $id = \App\Core\Db::insert('smm_providers', ['name' => 'x', 'api_url' => 'https://x.example/api/v2', 'is_active' => 1, 'created_at' => \App\Support\Clock::sql(), 'updated_at' => \App\Support\Clock::sql()]);
    T::same(['INR', 1_000_000], [\App\Core\Db::val('SELECT currency FROM smm_providers WHERE id = ?', [$id]), (int) \App\Core\Db::val('SELECT rate_to_inr_micros FROM smm_providers WHERE id = ?', [$id])]);
});
