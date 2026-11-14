<div>
    <x-ui.flash />

    <x-ui.page-header title="Referral Management" description="Both players in a referral always get their own, independently configured reward — credited to the existing Deposit Wallet, never a separate one." />

    @include('livewire.admin.referrals._nav')

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat label="Successful referrals" :value="number_format($totalReferrals)" />
        <x-ui.stat label="Coins to new players" :value="number_format((float) $totalNewPlayerCoins, 2)" />
        <x-ui.stat label="Coins to referrers" :value="number_format((float) $totalReferrerCoins, 2)" />
    </div>

    <form wire:submit="save" novalidate>
        @error('values')<x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror
        <x-ui.form-section title="Referral system" description="Off by default. Every player still gets a unique referral code regardless — turning this on only starts crediting rewards for new signups.">
            @include('livewire.admin.ads._setting', ['key' => 'referral_enabled'])
        </x-ui.form-section>

        <x-ui.form-section title="Rewards" description="Credited once, atomically, the moment a valid code is used at signup. Changing these never affects referrals that already happened.">
            @include('livewire.admin.ads._setting', ['key' => 'referral_new_player_reward', 'hint' => 'Credited to the new player who enters the code.'])
            @include('livewire.admin.ads._setting', ['key' => 'referral_referrer_reward', 'hint' => 'Credited to the player whose code was used.'])
            @include('livewire.admin.ads._setting', ['key' => 'referral_max_per_referrer', 'hint' => 'Leave blank for unlimited.'])
        </x-ui.form-section>

        <div class="mb-6 flex justify-end"><x-ui.button type="submit" loading="save" icon="check">Save</x-ui.button></div>
    </form>

    @include('livewire.admin.ads._history')
</div>
