<div>
    <x-ui.page-header title="User ad activity" description="Look up one player's ads, rewards and Ads Earning eligibility." />

    @include('livewire.admin.ads._nav')

    <x-ui.card class="mb-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.input label="Player (ID or exact username)" wire:model.live.debounce.400ms="player" placeholder="e.g. 42 or ShadowAce" wrapperClass="lg:col-span-2" />
            <x-ui.select label="Period" wire:model.live="range">
                @foreach (\App\Domain\Ads\AdsAnalytics::RANGES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            @if ($range === 'custom')
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
            @endif
        </div>
        @if ($matches->isNotEmpty())
            <p class="mt-3 text-xs text-fg-3">Did you mean:
                @foreach ($matches as $match)
                    <button type="button" class="text-brand-text hover:underline" wire:click="$set('player', '{{ $match->id }}')">{{ $match->username }}</button>@if (! $loop->last), @endif
                @endforeach
            </p>
        @elseif ($player !== '' && ! $user)
            <p class="mt-3 text-xs text-fg-3">No player matches.</p>
        @endif
    </x-ui.card>

    @if ($user && $stats)
        <x-ui.card :title="$user->username.' (#'.$user->id.')'" class="mb-4">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.kv label="Ads Earning"><x-ui.badge :status="$stats['opted_in'] ? 'active' : 'inactive'">{{ $stats['opted_in'] ? 'On' : 'Off' }}</x-ui.badge></x-ui.kv>
                <x-ui.kv label="Eligibility"><x-ui.badge :variant="$stats['eligibility']['eligible'] ? 'success' : 'warning'">{{ $stats['eligibility']['eligible'] ? 'Eligible' : 'Not yet eligible' }}</x-ui.badge></x-ui.kv>
                <x-ui.kv label="Successful deposits"><span class="inline-flex items-center gap-1.5 tabular-nums"><x-coin-asset :size="16" />{{ number_format((float) $stats['eligibility']['total'], 2) }} of {{ number_format((float) $stats['eligibility']['required'], 2) }}</span> @if (! $stats['eligibility']['eligible'])<span class="text-fg-3">({{ number_format((float) $stats['eligibility']['remaining'], 2) }} to go)</span>@endif</x-ui.kv>
                <x-ui.kv label="Ad impressions (period)">{{ number_format($stats['impressions']) }}</x-ui.kv>
                <x-ui.kv label="Rewarded ads started">{{ number_format($stats['rewarded_started']) }}</x-ui.kv>
                <x-ui.kv label="Completed (verified)">{{ number_format($stats['rewarded_completed']) }} @if ($stats['rewarded_simulated'])<span class="text-fg-3">+ {{ $stats['rewarded_simulated'] }} simulated (QA)</span>@endif</x-ui.kv>
                <x-ui.kv label="Not completed">{{ number_format($stats['rewarded_failed']) }}</x-ui.kv>
                <x-ui.kv label="Rewards credited (period)">{{ number_format($stats['rewards_credited']) }}</x-ui.kv>
                <x-ui.kv label="Pending weekly allocation">{{ number_format((float) $stats['pending_allocation_coins'], 2) }} coins</x-ui.kv>
                <x-ui.kv label="Ad reward coins credited (all time)">{{ number_format((float) $stats['credited_reward_coins'], 2) }}</x-ui.kv>
                <x-ui.kv label="Last ad event">{{ $stats['last_event_at'] ? \Carbon\Carbon::parse($stats['last_event_at'])->format('d M Y H:i') : '—' }}</x-ui.kv>
                <x-ui.kv label="Last settlement credit">{{ $stats['last_settlement_at'] ? \Carbon\Carbon::parse($stats['last_settlement_at'])->format('d M Y H:i') : '—' }}</x-ui.kv>
            </dl>
        </x-ui.card>

        <h2 class="mb-2 text-sm font-semibold text-fg">Rewarded ads</h2>
        <x-ui.table label="Rewarded ads" class="mb-6">
            <x-slot:head><tr><th>Started</th><th>Reward type</th><th>Status</th><th>Verified by</th><th class="text-right">Coins</th><th>Ledger ref</th><th>Note</th></tr></x-slot:head>
            @forelse ($claims as $claim)
                <tr wire:key="uc-{{ $claim->id }}">
                    <td class="whitespace-nowrap">{{ $claim->created_at->format('d M H:i') }}</td>
                    <td>{{ \App\Domain\Ads\AdsAnalytics::REWARD_TYPES[$claim->purpose] ?? $claim->purpose }}</td>
                    <td><x-ui.badge :status="$claim->status === 'verified' ? 'success' : $claim->status">{{ ucfirst($claim->status) }}</x-ui.badge></td>
                    <td>{{ $claim->verification_method === 'levelplay_s2s' ? 'LevelPlay callback' : ($claim->verification_method === 'simulated' ? 'Simulated (QA)' : '—') }}</td>
                    <td class="text-right tabular-nums">{{ $claim->reward_coins !== null ? number_format((float) $claim->reward_coins, 2) : ($claim->purpose === 'ads_earning' ? ($claim->counts_for_earning ? '1 unit' : '—') : '—') }}</td>
                    <td class="font-mono text-xs">{{ $claim->wallet_ledger_entry_id ? 'L'.$claim->wallet_ledger_entry_id : '—' }}</td>
                    <td class="text-fg-3">{{ $claim->failure_reason ? str_replace('_', ' ', $claim->failure_reason) : '' }}</td>
                </tr>
            @empty
                <x-ui.table-empty :colspan="7" icon="eye" title="No rewarded ads in this period" />
            @endforelse
            <x-slot:footer>{{ $claims->links() }}</x-slot:footer>
        </x-ui.table>

        <h2 class="mb-2 text-sm font-semibold text-fg">Weekly allocations</h2>
        <x-ui.table label="Weekly allocations">
            <x-slot:head><tr><th>Week</th><th class="text-right">Units</th><th class="text-right">Coins</th><th>Status</th><th>Ledger ref</th></tr></x-slot:head>
            @forelse ($allocations as $allocation)
                <tr wire:key="ua-{{ $allocation->id }}">
                    <td class="whitespace-nowrap">{{ $allocation->batch->period_start->format('d M') }} – {{ $allocation->batch->period_end->format('d M Y') }}</td>
                    <td class="text-right tabular-nums">{{ $allocation->units }}</td>
                    <td class="text-right tabular-nums">{{ number_format((float) $allocation->coins, 2) }}</td>
                    <td><x-ui.badge :status="$allocation->status === 'credited' ? 'paid' : $allocation->status">{{ ucfirst(str_replace('_', ' ', $allocation->status)) }}</x-ui.badge></td>
                    <td class="font-mono text-xs">{{ $allocation->wallet_ledger_entry_id ? 'L'.$allocation->wallet_ledger_entry_id : '—' }}</td>
                </tr>
            @empty
                <x-ui.table-empty :colspan="5" icon="salary" title="No settlement allocations yet" />
            @endforelse
        </x-ui.table>
    @elseif ($player === '')
        <x-ui.empty-state icon="users" title="Search for a player" description="Enter a player ID or username to see their ad activity." />
    @endif
</div>
