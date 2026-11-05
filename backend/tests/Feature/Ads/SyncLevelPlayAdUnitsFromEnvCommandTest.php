<?php

namespace Tests\Feature\Ads;

use App\Models\AdUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncLevelPlayAdUnitsFromEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_ad_units_from_configured_env_values(): void
    {
        config([
            'minimax.levelplay_rewarded_ad_unit_id' => 'rewarded-from-env',
            'minimax.levelplay_native_ad_unit_id' => 'native-from-env',
            'minimax.levelplay_interstitial_ad_unit_id' => 'interstitial-from-env',
        ]);

        $this->artisan('ads:sync-env-units')->assertExitCode(0);

        $this->assertSame('rewarded-from-env', AdUnit::where('format', 'rewarded')->first()?->levelplay_ad_unit_id);
        $this->assertSame('native-from-env', AdUnit::where('format', 'native')->first()?->levelplay_ad_unit_id);
        $this->assertSame('interstitial-from-env', AdUnit::where('format', 'interstitial')->first()?->levelplay_ad_unit_id);
        $this->assertSame(3, AdUnit::count());
    }

    public function test_it_skips_formats_with_no_configured_value(): void
    {
        config(['minimax.levelplay_rewarded_ad_unit_id' => null]);

        $this->artisan('ads:sync-env-units')->assertExitCode(0);

        $this->assertSame(0, AdUnit::where('format', 'rewarded')->count());
    }

    public function test_it_never_overwrites_an_ad_unit_admin_already_created(): void
    {
        $existing = AdUnit::create([
            'name' => 'Admin-created rewarded unit',
            'format' => 'rewarded',
            'environment' => 'production',
            'levelplay_ad_unit_id' => 'admin-chosen-unit-id',
        ]);

        config(['minimax.levelplay_rewarded_ad_unit_id' => 'should-not-be-used']);

        $this->artisan('ads:sync-env-units')->assertExitCode(0);

        $this->assertSame(1, AdUnit::where('format', 'rewarded')->count());
        $this->assertSame('admin-chosen-unit-id', $existing->refresh()->levelplay_ad_unit_id);
    }

    public function test_it_never_enables_any_placement(): void
    {
        config(['minimax.levelplay_rewarded_ad_unit_id' => 'rewarded-from-env']);

        $this->artisan('ads:sync-env-units')->assertExitCode(0);

        $this->assertDatabaseMissing('ad_placements', ['key' => 'tournament_ticket', 'is_enabled' => true]);
    }
}
