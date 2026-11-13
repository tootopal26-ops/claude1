<?php

namespace Tests\Feature\Ads;

use App\Domain\Ads\AdsConfig;
use App\Models\AdPlacement;
use App\Models\AdUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-format global kill switches (ads_native_enabled, ads_banner_enabled,
 * ads_interstitial_enabled, ads_app_open_enabled — ads_rewarded_enabled already existed)
 * added alongside ads_master_enabled. Covers the combinations from the user's test matrix
 * that are exercisable without a device: global off, global on with every format off,
 * global on with exactly one format on, and a missing/inactive ad unit never activating a
 * placement regardless of the switches.
 *
 * key/format/is_supported aren't in AdPlacement's #[Fillable] list (Admin only ever edits
 * is_enabled/ad_unit_id on the fixed, migration-seeded catalog) — forceFill() is the
 * correct way to set them on a one-off test fixture, same as a factory would.
 *
 * app_open has no real ad-unit case: ad_units.format's own database CHECK constraint is
 * ['rewarded', 'interstitial', 'banner', 'native'] only — an app_open ad unit cannot exist
 * at all, by design, because the mediation SDK has no app-open format (see the seeded
 * app_open placement's is_supported = false). Its switch is still tested directly below,
 * at the level that's actually meaningful today: AdsConfig::formatEnabled().
 *
 * AdsConfig::placements() memoizes its query on first access — every placement fixture a
 * test needs must exist BEFORE the first call to isPlacementActive()/clientConfig(),
 * otherwise a placement created after that point is invisible for the rest of that
 * instance's life. Each test below creates every fixture first, then resolves AdsConfig.
 */
class AdsFormatControlsTest extends TestCase
{
    use RefreshDatabase;

    private const array REAL_FORMATS = ['rewarded', 'native', 'banner', 'interstitial'];

    private function makePlacement(string $format, bool $active = true): AdPlacement
    {
        $unit = AdUnit::create(['name' => 'Unit', 'format' => $format, 'environment' => 'production', 'levelplay_ad_unit_id' => 'unit-'.$format, 'is_active' => $active]);

        $placement = new AdPlacement;
        $placement->forceFill([
            'key' => 'test_'.$format, 'name' => 'Test '.$format, 'format' => $format,
            'is_supported' => true, 'is_enabled' => true, 'ad_unit_id' => $unit->id, 'description' => 'Test fixture.',
        ]);
        $placement->save();

        return $placement;
    }

    private function allFormatsConfig(bool $value): array
    {
        return [
            'minimax.ads_rewarded_enabled' => $value,
            'minimax.ads_native_enabled' => $value,
            'minimax.ads_banner_enabled' => $value,
            'minimax.ads_interstitial_enabled' => $value,
            'minimax.ads_app_open_enabled' => $value,
        ];
    }

    public function test_global_off_disables_every_format_even_with_everything_else_on(): void
    {
        config(['minimax.ads_master_enabled' => false] + $this->allFormatsConfig(true));
        $placements = array_map(fn (string $format) => $this->makePlacement($format), self::REAL_FORMATS);

        $config = app(AdsConfig::class);
        foreach ($placements as $placement) {
            $this->assertFalse($config->isPlacementActive($placement->key), "{$placement->format} should be inactive when global ads are off.");
        }
        $this->assertFalse($config->formatEnabled('app_open'), 'app_open should be inactive when global ads are off.');
    }

    public function test_global_on_with_every_format_off_disables_every_format(): void
    {
        config(['minimax.ads_master_enabled' => true] + $this->allFormatsConfig(false));
        $placements = array_map(fn (string $format) => $this->makePlacement($format), self::REAL_FORMATS);

        $config = app(AdsConfig::class);
        foreach ($placements as $placement) {
            $this->assertFalse($config->isPlacementActive($placement->key), "{$placement->format} should be inactive when its own switch is off.");
        }
        $this->assertFalse($config->formatEnabled('app_open'), 'app_open should be inactive when its own switch is off.');
    }

    private function assertOnlyOneFormatActive(string $onFormat): void
    {
        config(['minimax.ads_master_enabled' => true] + $this->allFormatsConfig(false));
        config(['minimax.ads_'.$onFormat.'_enabled' => true]);
        $placements = array_map(fn (string $format) => $this->makePlacement($format), self::REAL_FORMATS);

        $config = app(AdsConfig::class);
        foreach ($placements as $placement) {
            $this->assertSame($placement->format === $onFormat, $config->isPlacementActive($placement->key), "{$placement->format} activation did not match expectation with only {$onFormat} switched on.");
        }
    }

    public function test_only_rewarded_on_activates_only_rewarded_placements(): void
    {
        $this->assertOnlyOneFormatActive('rewarded');
    }

    public function test_only_native_on_activates_only_native_placements(): void
    {
        $this->assertOnlyOneFormatActive('native');
    }

    public function test_only_banner_on_activates_only_banner_placements(): void
    {
        $this->assertOnlyOneFormatActive('banner');
    }

    public function test_only_interstitial_on_activates_only_interstitial_placements(): void
    {
        $this->assertOnlyOneFormatActive('interstitial');
    }

    public function test_app_open_switch_is_independently_master_and_self_gated(): void
    {
        config(['minimax.ads_master_enabled' => true, 'minimax.ads_app_open_enabled' => false]);
        $this->assertFalse(app(AdsConfig::class)->formatEnabled('app_open'));

        config(['minimax.ads_master_enabled' => true, 'minimax.ads_app_open_enabled' => true]);
        $this->assertTrue(app(AdsConfig::class)->formatEnabled('app_open'));

        config(['minimax.ads_master_enabled' => false, 'minimax.ads_app_open_enabled' => true]);
        $this->assertFalse(app(AdsConfig::class)->formatEnabled('app_open'), 'Master off must override the format switch.');
    }

    public function test_a_missing_ad_unit_never_activates_a_placement_regardless_of_switches(): void
    {
        config(['minimax.ads_master_enabled' => true, 'minimax.ads_native_enabled' => true]);

        $placement = new AdPlacement;
        $placement->forceFill(['key' => 'test_no_unit', 'name' => 'No unit', 'format' => 'native', 'is_supported' => true, 'is_enabled' => true, 'ad_unit_id' => null, 'description' => 'Test fixture.']);
        $placement->save();

        $this->assertFalse(app(AdsConfig::class)->isPlacementActive($placement->key));
    }

    public function test_an_inactive_ad_unit_never_activates_a_placement_regardless_of_switches(): void
    {
        config(['minimax.ads_master_enabled' => true, 'minimax.ads_banner_enabled' => true]);

        $placement = $this->makePlacement('banner', active: false);

        $this->assertFalse(app(AdsConfig::class)->isPlacementActive($placement->key));
    }

    public function test_client_config_omits_every_placement_when_global_ads_are_off(): void
    {
        config(['minimax.ads_master_enabled' => false]);
        $this->makePlacement('native');

        $clientConfig = app(AdsConfig::class)->clientConfig();

        $this->assertFalse($clientConfig['enabled']);
        $this->assertSame([], $clientConfig['placements']);
    }
}
