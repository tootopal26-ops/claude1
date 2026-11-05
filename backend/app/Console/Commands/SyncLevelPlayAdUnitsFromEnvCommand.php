<?php

namespace App\Console\Commands;

use App\Models\AdUnit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Bootstraps ad units from environment variables so a fresh deploy doesn't need Admin to
 * hand-type real LevelPlay ad unit IDs before anything can be switched on.
 *
 * For each of the rewarded, native and interstitial formats, if LEVELPLAY_*_AD_UNIT_ID is
 * set and no ad unit of that format exists yet, creates one (is_active = true). It never
 * overwrites or replaces an ad unit Admin already created or edited — Admin > Ads &
 * Monetization stays the source of truth once anything exists there — and it never enables
 * a placement or turns ads on: that stays a deliberate Admin action (docs/ads-monetization.md
 * §5). Safe to run on every deploy; a second run with the same or no env vars changes nothing.
 */
#[Signature('ads:sync-env-units')]
#[Description('Create LevelPlay ad units from LEVELPLAY_REWARDED_AD_UNIT_ID / LEVELPLAY_NATIVE_AD_UNIT_ID / LEVELPLAY_INTERSTITIAL_AD_UNIT_ID when none of that format exist yet')]
class SyncLevelPlayAdUnitsFromEnvCommand extends Command
{
    /** Ad unit format => the minimax config key holding its env-provided ad unit ID. */
    private const array CONFIG_KEY_BY_FORMAT = [
        'rewarded' => 'levelplay_rewarded_ad_unit_id',
        'native' => 'levelplay_native_ad_unit_id',
        'interstitial' => 'levelplay_interstitial_ad_unit_id',
    ];

    public function handle(): int
    {
        foreach (self::CONFIG_KEY_BY_FORMAT as $format => $configKey) {
            $adUnitId = config("minimax.{$configKey}");

            if (! is_string($adUnitId) || $adUnitId === '') {
                $this->line(ucfirst($format).' — no ad unit ID configured, skipping.');

                continue;
            }

            if (AdUnit::where('format', $format)->where('environment', 'production')->exists()) {
                $this->line(ucfirst($format)." — an ad unit already exists; Admin's configuration was left untouched.");

                continue;
            }

            $unit = AdUnit::create([
                'name' => 'Env-configured '.$format.' unit',
                'format' => $format,
                'environment' => 'production',
                'levelplay_ad_unit_id' => $adUnitId,
                'is_active' => true,
            ]);

            $this->line(ucfirst($format)." — created ad unit #{$unit->id} ({$adUnitId}).");
        }

        $this->line('Placements are never auto-enabled by this command — pick the ad unit and switch each placement on in Admin > Ads & Monetization when ready to go live.');

        return self::SUCCESS;
    }
}
