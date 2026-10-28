<div>
    <x-ui.flash />

    <x-ui.page-header title="Daily login rewards" description="A once-a-day reward offered in a popup to eligible players. The amount is drawn on the server within the range and credited to the Deposit balance only after the rewarded ad is verified." />

    @include('livewire.admin.ads._nav')

    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Credited today ({{ $today }})" :value="number_format($stats['credited_today'])" tone="success" />
        <x-ui.stat label="Waiting for the ad today" :value="number_format($stats['reserved_today'])" hint="Reserved; not credited unless the ad is verified." tone="warning" />
        <x-ui.stat label="Hidden for today" :value="number_format($stats['dismissed_today'])" hint="Players who ticked “Don't show again today”." />
        <x-ui.stat label="Last 7 days" :value="number_format($stats['credited_week']).' claims'" :hint="number_format((float) $stats['coins_week'], 2).' coins credited'" />
        <x-ui.stat label="Daily-reward ads started (7 d)" :value="number_format($stats['ads_started_week'])" />
        <x-ui.stat label="Verified by the network (7 d)" :value="number_format($stats['ads_verified_week'])" tone="success" />
        <x-ui.stat label="Not verified (7 d)" :value="number_format($stats['ads_failed_week'])" hint="Closed early, expired without a callback, or rejected." tone="danger" />
    </div>

    <form wire:submit="save" novalidate>
        @error('values')<x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror
        <x-ui.form-section title="Rules" description="Who sees the popup and what they can get.">
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_enabled'])
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_requires_ad', 'hint' => 'On: the reward is credited only after a verified rewarded ad. Off: players claim directly (no ad).'])
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_min_deposit', 'hint' => 'Provider-confirmed deposits only. Default 50.'])
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_timezone', 'hint' => 'The day resets at midnight here. Default Asia/Kolkata.'])
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_min_coins', 'hint' => 'Greater than zero.'])
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_max_coins', 'hint' => 'At least the minimum (checked on the server).'])
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_starts_on'])
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_ends_on'])
        </x-ui.form-section>
        <x-ui.form-section title="Popup" description="What players read. The popup always shows the Mini Max coin artwork.">
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_title'])
            @include('livewire.admin.ads._setting', ['key' => 'daily_reward_description'])
        </x-ui.form-section>
        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button type="submit" loading="save" icon="check">Save settings</x-ui.button>
        </div>
    </form>

    <h2 class="mb-2 mt-6 text-sm font-semibold text-fg">Recent claims</h2>
    <x-ui.table label="Daily reward claims">
        <x-slot:head><tr><th>Day</th><th>Player</th><th class="text-right">Amount</th><th>Status</th><th>Ledger ref</th><th>Credited</th></tr></x-slot:head>
        @forelse ($claims as $claim)
            <tr wire:key="dc-{{ $claim->id }}">
                <td class="whitespace-nowrap">{{ $claim->reward_day->format('d M Y') }}</td>
                <td class="mm-cell-primary">{{ $claim->user?->username ?? '#'.$claim->user_id }}</td>
                <td class="text-right tabular-nums">{{ number_format((float) $claim->amount, 2) }}</td>
                <td><x-ui.badge :status="$claim->status === 'credited' ? 'paid' : 'pending'">{{ $claim->status === 'credited' ? 'Credited' : 'Waiting for ad' }}</x-ui.badge></td>
                <td class="font-mono text-xs">{{ $claim->wallet_ledger_entry_id ? 'L'.$claim->wallet_ledger_entry_id : '—' }}</td>
                <td class="whitespace-nowrap text-fg-3">{{ $claim->credited_at?->format('d M Y H:i') ?? '—' }}</td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="6" icon="calendar" title="No daily reward claims yet" />
        @endforelse
        <x-slot:footer>{{ $claims->links() }}</x-slot:footer>
    </x-ui.table>

    @include('livewire.admin.ads._history')
</div>
