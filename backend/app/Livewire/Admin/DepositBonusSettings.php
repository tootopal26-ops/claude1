<?php

namespace App\Livewire\Admin;

use App\Domain\Payments\DepositBonusService;
use App\Domain\Settings\PlatformSettingsService;
use App\Livewire\Admin\Ads\Concerns\EditsSettings;
use App\Models\StaffUser;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin > Deposit Bonus Management: the first-deposit bonus settings, lifetime totals, and
 * award history. See App\Domain\Payments\DepositBonusService — the only place a bonus is
 * ever actually credited, from DepositService's webhook-verified payment confirmation.
 */
#[Layout('components.layouts.admin')]
class DepositBonusSettings extends Component
{
    use EditsSettings;

    protected function settingKeys(): array
    {
        return [
            'deposit_bonus_enabled', 'deposit_bonus_amount', 'deposit_bonus_min_deposit',
            'deposit_bonus_max_per_player', 'deposit_bonus_first_deposit_only',
        ];
    }

    protected function actor(): StaffUser
    {
        /** @var StaffUser $staff */
        $staff = Auth::guard('staff')->user();

        return $staff;
    }

    protected function ensureCanManage(): void
    {
        abort_unless($this->actor()->hasPermission('deposit_bonus.manage'), 403);
    }

    public function mount(PlatformSettingsService $settings): void
    {
        $this->ensureCanManage();
        $this->loadSettings($settings);
    }

    public function save(PlatformSettingsService $settings): void
    {
        $this->saveSettings($settings, 'Deposit bonus settings saved.');
    }

    public function render(DepositBonusService $depositBonus)
    {
        $this->ensureCanManage();

        return view('livewire.admin.deposit-bonus-settings', [
            'stats' => $depositBonus->stats(),
            'awards' => $depositBonus->history(),
            'history' => $this->settingsHistory(),
        ]);
    }
}
