<?php

namespace App\Livewire\Admin\Referrals;

use App\Domain\Settings\PlatformSettingsService;
use App\Livewire\Admin\Ads\Concerns\EditsSettings;
use App\Livewire\Admin\Referrals\Concerns\ReferralsPage;
use App\Models\Referral;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Referral Management > Settings: the master switch and both reward amounts. Turning the
 * system off only stops new referrals from being credited — past rewards and history are
 * never touched.
 */
#[Layout('components.layouts.admin')]
class Settings extends Component
{
    use EditsSettings;
    use ReferralsPage;

    protected function settingKeys(): array
    {
        return ['referral_enabled', 'referral_new_player_reward', 'referral_referrer_reward', 'referral_max_per_referrer'];
    }

    public function mount(PlatformSettingsService $settings): void
    {
        $this->loadSettings($settings);
    }

    public function save(PlatformSettingsService $settings): void
    {
        $this->saveSettings($settings, 'Referral settings saved. They apply to referrals from now on; past rewards are unchanged.');
    }

    public function render()
    {
        return view('livewire.admin.referrals.settings', [
            'history' => $this->settingsHistory(),
            'totalReferrals' => Referral::where('status', 'completed')->count(),
            'totalNewPlayerCoins' => (string) Referral::where('status', 'completed')->sum('new_player_reward'),
            'totalReferrerCoins' => (string) Referral::where('status', 'completed')->sum('referrer_reward'),
        ]);
    }
}
