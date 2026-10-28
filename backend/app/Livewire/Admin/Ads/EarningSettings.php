<?php

namespace App\Livewire\Admin\Ads;

use App\Domain\Settings\PlatformSettingsService;
use App\Livewire\Admin\Ads\Concerns\AdsPage;
use App\Livewire\Admin\Ads\Concerns\EditsSettings;
use App\Models\AuditLog;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ads & Monetization > Ads Earning: the Rewarded Ads switch (saved the moment it is
 * flipped), the global ad switches, and the Ads Earning revenue-share rules. Turning
 * anything off only stops new opportunities; earned rewards and history are never touched.
 */
#[Layout('components.layouts.admin')]
class EarningSettings extends Component
{
    use AdsPage;
    use EditsSettings;

    public bool $rewardedEnabled = false;

    public ?string $rewardedError = null;

    protected function settingKeys(): array
    {
        return [
            'ads_master_enabled', 'ads_test_mode', 'levelplay_app_key',
            'ads_earning_enabled', 'ads_earning_paused', 'ads_earning_user_toggle_allowed',
            'ads_earning_min_deposit', 'ads_earning_business_share', 'ads_earning_user_share',
            'ads_earning_coins_per_inr', 'ads_earning_daily_view_limit', 'ads_earning_min_payout_coins',
            'ads_earning_settlement_day', 'ads_earning_settlement_time', 'ads_earning_timezone',
            'ads_earning_explanation', 'ads_earning_admin_notes',
        ];
    }

    public function mount(PlatformSettingsService $settings): void
    {
        $this->loadSettings($settings);
        $this->rewardedEnabled = (bool) $settings->get('ads_rewarded_enabled');
    }

    /** The Rewarded Ads switch: saved straight away, then re-read from the server. */
    public function setRewarded(bool $enabled, PlatformSettingsService $settings): void
    {
        $this->ensureCanManage();
        $this->rewardedError = null;

        try {
            $settings->set('ads_rewarded_enabled', $enabled, $this->actor());
        } catch (ValidationException $exception) {
            $this->rewardedError = collect($exception->errors())->flatten()->first();
        }

        $this->rewardedEnabled = (bool) $settings->get('ads_rewarded_enabled');
        session()->flash('status', $this->rewardedEnabled ? 'Rewarded Ads enabled. New rewarded opportunities can be offered.' : 'Rewarded Ads disabled. No new rewarded opportunities are offered; past rewards are unchanged.');
    }

    public function save(PlatformSettingsService $settings): void
    {
        $this->saveSettings($settings, 'Ads Earning settings saved. They apply from now on.');
    }

    public function render()
    {
        return view('livewire.admin.ads.earning-settings', [
            'history' => $this->settingsHistory(),
            'rewardedHistory' => AuditLog::query()->where('action', 'platform_settings.updated')->where('metadata->key', 'ads_rewarded_enabled')->latest('id')->first(),
        ]);
    }
}
