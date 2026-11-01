<div>
    <x-ui.flash />

    <x-ui.page-header title="Spin & token rewards" description="A rewarded-ad spin wheel pays out 1-5 tokens on a server-side weighted draw, and players exchange tokens for coins in fixed tiers credited to the Deposit wallet. Every number and every coin amount here comes from the ledger, never the client." />

    @include('livewire.admin.ads._nav')

    @php
        $breakdown = collect([1, 2, 3, 4, 5])->mapWithKeys(fn (int $n) => [$n => (int) ($stats['breakdown_week'][$n] ?? 0)]);
    @endphp

    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Spins today" :value="number_format($stats['spins_today'])" :hint="number_format($stats['tokens_awarded_today']).' tokens awarded'" />
        <x-ui.stat label="Spins (7 d)" :value="number_format($stats['spins_week'])" :hint="number_format($stats['tokens_awarded_week']).' tokens awarded'" />
        <x-ui.stat label="Spin ads started / verified (7 d)" :value="number_format($stats['ads_started_week']).' / '.number_format($stats['ads_verified_week'])" tone="success" />
        <x-ui.stat label="Number breakdown (7 d)" :value="$breakdown->sum()" :hint="$breakdown->map(fn (int $c, int $n) => \"{$n}:{$c}\")->implode('  ')" />
        <x-ui.stat label="Exchanges (7 d)" :value="number_format($stats['exchanges_week'])" tone="success" />
        <x-ui.stat label="Tokens exchanged (7 d)" :value="number_format($stats['tokens_exchanged_week'])" />
        <x-ui.stat label="Coins credited via exchange (7 d)" :value="number_format((float) $stats['coins_exchanged_week'], 2)" :coin="true" tone="success" />
    </div>

    <form wire:submit="save" novalidate>
        @error('values')<x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror
        <x-ui.form-section title="Spin wheel" description="The wheel has 8 fixed segments (Number 1 ×3, Number 2 ×2, Number 3 ×1, Number 4 ×1, Number 5 ×1) — only the odds below are configurable, and they must always add up to exactly 100%.">
            @include('livewire.admin.ads._setting', ['key' => 'spin_enabled', 'hint' => 'Off: the Spin screen is hidden and no ad is requested for it.'])
            @include('livewire.admin.ads._setting', ['key' => 'spin_prob_1', 'hint' => 'Chance of winning 1 token.'])
            @include('livewire.admin.ads._setting', ['key' => 'spin_prob_2', 'hint' => 'Chance of winning 2 tokens.'])
            @include('livewire.admin.ads._setting', ['key' => 'spin_prob_3', 'hint' => 'Chance of winning 3 tokens.'])
            @include('livewire.admin.ads._setting', ['key' => 'spin_prob_4', 'hint' => 'Chance of winning 4 tokens.'])
            @include('livewire.admin.ads._setting', ['key' => 'spin_prob_5', 'hint' => 'Chance of winning 5 tokens.'])
        </x-ui.form-section>
        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button type="submit" loading="save" icon="check">Save settings</x-ui.button>
        </div>
    </form>

    <x-ui.card title="Exchange tiers" description="Fixed — not editable here. The first 4 tiers exchange at the standard 50 tokens : 1 coin rate; tiers 5-10 carry a +5% bonus." class="mt-6">
        <x-ui.table label="Token exchange tiers">
            <x-slot:head><tr><th>Tier</th><th class="text-right">Tokens</th><th class="text-right">Coins</th><th>Bonus</th></tr></x-slot:head>
            @foreach ($tiers as $tier)
                <tr wire:key="tier-{{ $tier['index'] }}">
                    <td class="mm-cell-primary">{{ $tier['index'] + 1 }}</td>
                    <td class="text-right tabular-nums">{{ number_format($tier['tokens']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format((float) $tier['coins'], 2) }}</td>
                    <td>{{ (float) $tier['bonus_percent'] > 0 ? '+'.number_format((float) $tier['bonus_percent'], 0).'%' : 'Standard' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <h2 class="mb-2 mt-6 text-sm font-semibold text-fg">Recent spins</h2>
    <x-ui.table label="Recent spin attempts">
        <x-slot:head><tr><th>Player</th><th class="text-right">Winning number</th><th class="text-right">Tokens awarded</th><th>When</th></tr></x-slot:head>
        @forelse ($spins as $spin)
            <tr wire:key="spin-{{ $spin->id }}">
                <td class="mm-cell-primary">{{ $spin->user?->username ?? '#'.$spin->user_id }}</td>
                <td class="text-right tabular-nums">{{ $spin->winning_number }}</td>
                <td class="text-right tabular-nums">{{ $spin->tokens_awarded }}</td>
                <td class="whitespace-nowrap text-fg-3">{{ $spin->created_at->format('d M Y H:i') }}</td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="4" icon="spin" title="No spins yet" />
        @endforelse
        <x-slot:footer>{{ $spins->links() }}</x-slot:footer>
    </x-ui.table>

    <h2 class="mb-2 mt-6 text-sm font-semibold text-fg">Recent exchanges</h2>
    <x-ui.table label="Recent token exchange transactions">
        <x-slot:head><tr><th>Player</th><th class="text-right">Tokens</th><th class="text-right">Coins</th><th>Status</th><th>When</th></tr></x-slot:head>
        @forelse ($exchanges as $exchange)
            <tr wire:key="exchange-{{ $exchange->id }}">
                <td class="mm-cell-primary">{{ $exchange->user?->username ?? '#'.$exchange->user_id }}</td>
                <td class="text-right tabular-nums">{{ number_format($exchange->tokens_required) }}</td>
                <td class="text-right tabular-nums">{{ number_format((float) $exchange->coins_received, 2) }}</td>
                <td>
                    <x-ui.badge :variant="$exchange->status === 'credited' ? 'success' : 'danger'">{{ $exchange->status === 'credited' ? 'Credited' : ucfirst(str_replace('_', ' ', $exchange->failure_reason ?? 'failed')) }}</x-ui.badge>
                </td>
                <td class="whitespace-nowrap text-fg-3">{{ $exchange->created_at->format('d M Y H:i') }}</td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="5" icon="wallet" title="No token exchanges yet" />
        @endforelse
        <x-slot:footer>{{ $exchanges->links() }}</x-slot:footer>
    </x-ui.table>

    @include('livewire.admin.ads._history')
</div>
