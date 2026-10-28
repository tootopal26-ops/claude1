<div>
    <x-ui.flash />

    <x-ui.page-header title="Ads Earning" description="Rewarded ads, the global ad switches and the Ads Earning revenue-share rules." />

    @include('livewire.admin.ads._nav')

    <x-ui.card title="Rewarded Ads" class="mb-6">
        <x-slot:actions>
            <x-ui.badge :variant="$rewardedEnabled ? 'success' : 'neutral'" data-testid="rewarded-status">{{ $rewardedEnabled ? 'Enabled' : 'Disabled' }}</x-ui.badge>
        </x-slot:actions>

        <p class="max-w-3xl text-sm text-fg-2">
            Rewarded Ads allow players to voluntarily watch an advertisement and earn coins as a reward. When this feature is enabled, eligible players can watch available rewarded ads in the app. After the ad network confirms that the ad was watched successfully, the backend can credit the configured reward to the player's Deposit Wallet. Turning this feature off prevents new rewarded-ad opportunities from being offered. Rewards must never be credited just because a player opened or closed an advertisement.
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <label class="inline-flex cursor-pointer items-center gap-3">
                <span class="relative inline-flex">
                    <input
                        type="checkbox"
                        role="switch"
                        class="peer sr-only"
                        aria-label="Rewarded Ads"
                        @checked($rewardedEnabled)
                        wire:click="setRewarded({{ $rewardedEnabled ? 'false' : 'true' }})"
                        wire:loading.attr="disabled"
                        wire:target="setRewarded"
                    >
                    <span class="h-6 w-11 rounded-full border border-line-strong bg-surface-3 transition-colors peer-checked:border-brand peer-checked:bg-brand peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-(--mm-ring) peer-disabled:opacity-50"></span>
                    <span class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-fg-2 transition-transform peer-checked:translate-x-5 peer-checked:bg-on-brand motion-reduce:transition-none"></span>
                </span>
                <span class="text-sm font-medium text-fg">{{ $rewardedEnabled ? 'Enabled' : 'Disabled' }}</span>
            </label>
            <span wire:loading wire:target="setRewarded" class="text-xs text-fg-3">Saving…</span>
        </div>

        @if ($rewardedError)
            <x-ui.alert variant="danger" class="mt-3">{{ $rewardedError }}</x-ui.alert>
        @endif

        <p class="mt-3 text-xs text-fg-3">
            Rewards are credited only from the ad network's signed server-to-server callback (LevelPlay). All ads must also be on, and each rewarded placement switched on with an ad unit, before players see an offer.
            @if ($rewardedHistory)
                Last changed by {{ $rewardedHistory->actorLabel() }} on {{ $rewardedHistory->created_at->format('d M Y, H:i') }}.
            @endif
        </p>
    </x-ui.card>

    <form wire:submit="save" novalidate>
        @error('values')<x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror

        <x-ui.form-section title="All ads" description="The global switch overrides every placement. Off means no ad of any kind is requested.">
            @include('livewire.admin.ads._setting', ['key' => 'ads_master_enabled', 'hint' => 'Off stops every new ad request and rewarded opportunity at once. History is kept.'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_test_mode', 'hint' => 'While on, every ad event is recorded as test-only and never counted as revenue. Use LevelPlay test devices / AdMob test ads.'])
            @include('livewire.admin.ads._setting', ['key' => 'levelplay_app_key', 'hint' => 'Public LevelPlay app key (it ships inside the app anyway). The server-to-server private key is set only in the server environment, never here.', 'wide' => true])
        </x-ui.form-section>

        <x-ui.form-section title="Ads Earning" description="The weekly revenue share. One unit = one rewarded ad on the Ads Earning placement, verified by the network, by an eligible player who switched it on, within the daily limit.">
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_enabled'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_paused', 'hint' => 'Maintenance: no new earning ads; settlements and history unaffected.'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_user_toggle_allowed', 'wide' => true])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_min_deposit', 'hint' => 'From provider-confirmed deposit orders only. Default 50.'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_daily_view_limit'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_business_share', 'hint' => 'Business + players must total exactly 100% (checked on the server).'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_user_share', 'hint' => 'A policy split of finalized revenue, not a guaranteed amount.'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_coins_per_inr', 'hint' => 'Conversion policy: coins credited per INR 1 of the players\' pool. Recorded on each settlement batch.'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_min_payout_coins', 'hint' => 'Allocations below this aren\'t credited; they are recorded on the batch.'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_settlement_day'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_settlement_time', 'hint' => 'The week runs from this cutoff to the next.'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_timezone', 'hint' => 'Default Asia/Kolkata.'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_explanation'])
            @include('livewire.admin.ads._setting', ['key' => 'ads_earning_admin_notes'])
        </x-ui.form-section>

        <x-ui.form-section title="Reward calculation status" description="Where the weekly revenue share stands right now.">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:col-span-2 sm:grid-cols-2">
                <x-ui.kv label="Latest settlement">
                    @if ($latestBatch)
                        <x-ui.badge :status="$latestBatch->status === 'calculated' ? 'approved' : $latestBatch->status">{{ ucfirst($latestBatch->status) }}</x-ui.badge>
                        <span class="text-fg-3">#{{ $latestBatch->id }} · {{ $latestBatch->period_start->setTimezone(config('minimax.ads_earning_timezone'))->format('d M') }} – {{ $latestBatch->period_end->setTimezone(config('minimax.ads_earning_timezone'))->format('d M Y') }}</span>
                        <a href="{{ portal_route('ads.settlements', ['batch' => $latestBatch->id]) }}" class="text-brand-text hover:underline">Open</a>
                    @else
                        <span class="text-fg-3">None yet — each finished week is opened automatically.</span>
                    @endif
                </x-ui.kv>
                <x-ui.kv label="Verified units awaiting calculation">{{ number_format($unitsAwaiting) }}</x-ui.kv>
            </dl>
        </x-ui.form-section>

        <x-ui.form-section title="Eligible placements and reward types" description="Fixed by design so one ad can never earn twice.">
            <div class="text-sm text-fg-2 sm:col-span-2">
                <p>Ads Earning counts only the <strong>Ads Earning</strong> rewarded placement (Model A — revenue share). Tickets, the daily login reward, redeem codes and reward campaigns use their own placements and accounting, and never add Ads Earning units. Banner, native and interstitial impressions never earn players anything: paying for ad views is allowed only through rewarded inventory.</p>
            </div>
        </x-ui.form-section>

        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button type="submit" loading="save" icon="check" confirm="Save these Ads Earning settings? They apply from now on; settlements already calculated keep the values they recorded." confirm-title="Save Ads Earning settings" confirm-label="Save">Save settings</x-ui.button>
        </div>
    </form>

    @include('livewire.admin.ads._history')
</div>
