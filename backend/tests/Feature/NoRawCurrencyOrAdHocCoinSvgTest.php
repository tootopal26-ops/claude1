<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Mini Max shows every amount as coins, not rupees, and the one coin
 * graphic is always <x-coin-asset> (see app/View/Components/CoinAsset.php)
 * — never a raw ₹ symbol or a hand-rolled coin SVG that would drift from
 * it. This guards that invariant structurally: no ₹ character anywhere
 * in the API resources the mobile app consumes or in any Blade view, and
 * no inline <svg> markup outside the two components (Icon, CoinAsset)
 * that are allowed to define shapes directly.
 */
class NoRawCurrencyOrAdHocCoinSvgTest extends TestCase
{
    public function test_no_api_resource_embeds_a_raw_rupee_symbol(): void
    {
        $this->assertNoMatches(
            app_path('Http/Resources'),
            '/\x{20B9}/u',
            'raw ₹ symbol',
        );
    }

    public function test_no_blade_view_embeds_a_raw_rupee_symbol(): void
    {
        $this->assertNoMatches(
            resource_path('views'),
            '/\x{20B9}/u',
            'raw ₹ symbol',
        );
    }

    public function test_no_blade_view_outside_the_icon_components_defines_an_inline_svg(): void
    {
        $allowed = [
            resource_path('views/components/icon.blade.php'),
            resource_path('views/components/coin-asset.blade.php'),
            // Laravel's default framework stub, decorative only, unrelated
            // to the product's coin/currency UI.
            resource_path('views/welcome.blade.php'),
        ];

        $this->assertNoMatches(
            resource_path('views'),
            '/<svg\b/i',
            'an inline <svg> tag outside the shared Icon/CoinAsset components',
            $allowed,
        );
    }

    /**
     * @param  list<string>  $excludePaths
     */
    private function assertNoMatches(string $directory, string $pattern, string $description, array $excludePaths = []): void
    {
        $offenders = [];

        foreach (File::allFiles($directory) as $file) {
            $path = $file->getPathname();

            if (! str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
                continue;
            }

            if (in_array($path, $excludePaths, true)) {
                continue;
            }

            $contents = file_get_contents($path);

            if ($contents !== false && preg_match($pattern, $contents) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, "Found {$description} in: ".implode(', ', $offenders));
    }
}
