@php($fmtCoins = fn ($value) => number_format((float) $value, 2))
<div>
    <x-ui.flash />

    <x-ui.page-header title="Weekly settlements" description="Ads Earning revenue share: finalized provider revenue for the Ads Earning placement, split {{ config('minimax.ads_earning_business_share') }}% business / {{ config('minimax.ads_earning_user_share') }}% players, converted and divided by verified ads. Nothing is credited until an Admin credits a calculated settlement.">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="plus" wire:click="openLastWeek" loading="openLastWeek">Open last week</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('livewire.admin.ads._nav')

    @error('batch')<x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror

    <x-ui.table label="Settlement batches" class="mb-6">
        <x-slot:head><tr><th>Batch</th><th>Week ({{ $timezone }})</th><th>Status</th><th class="text-right">Revenue</th><th class="text-right">Units</th><th class="text-right">Players</th><th class="text-right">Allocated</th><th class="text-right">Credited</th><th></th></tr></x-slot:head>
        @forelse ($batches as $row)
            <tr wire:key="batch-{{ $row->id }}" @class(['bg-brand-soft/40' => $selected?->id === $row->id])>
                <td class="mm-cell-primary">#{{ $row->id }}</td>
                <td class="whitespace-nowrap">{{ $row->period_start->setTimezone($timezone)->format('d M H:i') }} – {{ $row->period_end->setTimezone($timezone)->format('d M Y H:i') }}</td>
                <td><x-ui.badge :status="$row->status === 'calculated' ? 'approved' : $row->status">{{ ucfirst($row->status) }}</x-ui.badge></td>
                <td class="text-right tabular-nums">${{ number_format((float) $row->eligible_revenue_usd, 4) }}</td>
                <td class="text-right tabular-nums">{{ number_format($row->total_units) }}</td>
                <td class="text-right tabular-nums">{{ number_format($row->eligible_users) }}</td>
                <td class="text-right tabular-nums">{{ $fmtCoins($row->total_coins_allocated) }}</td>
                <td class="text-right tabular-nums">{{ $fmtCoins($row->total_coins_credited) }}</td>
                <td class="text-right"><x-ui.button size="sm" variant="ghost" wire:click="select({{ $row->id }})">View</x-ui.button></td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="9" icon="salary" title="No settlements yet" description="Each finished week is opened automatically (ads:maintain), or open last week now." />
        @endforelse
        <x-slot:footer>{{ $batches->links() }}</x-slot:footer>
    </x-ui.table>

    @if ($selected)
        <x-ui.card :title="'Settlement #'.$selected->id" :description="'Policy '.$selected->policy_version.' · shares and conversion recorded when the batch was opened'" class="mb-6">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.kv label="Status"><x-ui.badge :status="$selected->status === 'calculated' ? 'approved' : $selected->status">{{ ucfirst($selected->status) }}</x-ui.badge></x-ui.kv>
                <x-ui.kv label="Finalized Ads Earning revenue now">${{ number_format((float) $revenue, 6) }} @if ((float) $revenue <= 0)<span class="text-warning">— none finalized yet</span>@endif</x-ui.kv>
                <x-ui.kv label="Revenue used">${{ number_format((float) $selected->eligible_revenue_usd, 6) }}</x-ui.kv>
                <x-ui.kv label="Business share">{{ $selected->business_share_pct }}% · ${{ number_format((float) $selected->business_share_usd, 6) }}</x-ui.kv>
                <x-ui.kv label="Players' pool">{{ $selected->user_share_pct }}% · ${{ number_format((float) $selected->user_pool_usd, 6) }}</x-ui.kv>
                <x-ui.kv label="USD → INR">{{ $selected->usd_inr_rate ? $selected->usd_inr_rate.' ('.$selected->rate_source.', '.$selected->rate_at?->format('d M Y H:i').')' : '—' }}</x-ui.kv>
                <x-ui.kv label="Pool in INR">INR {{ number_format((float) $selected->user_pool_inr, 4) }}</x-ui.kv>
                <x-ui.kv label="Coins per INR 1">{{ $selected->coins_per_inr }}</x-ui.kv>
                <x-ui.kv label="Units / players">{{ number_format($selected->total_units) }} / {{ number_format($selected->eligible_users) }}</x-ui.kv>
                <x-ui.kv label="Allocated / credited">{{ $fmtCoins($selected->total_coins_allocated) }} / {{ $fmtCoins($selected->total_coins_credited) }} coins</x-ui.kv>
                <x-ui.kv label="Remainder (rounding, below minimum)">{{ number_format((float) $selected->remainder_coins, 4) }} coins — recorded, not paid</x-ui.kv>
                <x-ui.kv label="Calculated / completed">{{ $selected->calculated_at?->format('d M Y H:i') ?? '—' }} / {{ $selected->completed_at?->format('d M Y H:i') ?? '—' }}</x-ui.kv>
            </dl>

            @if ($canManage && in_array($selected->status, ['pending', 'calculated'], true))
                <form wire:submit="calculate" class="mt-5 grid grid-cols-1 gap-3 border-t border-line pt-4 sm:grid-cols-3" novalidate>
                    <x-ui.input label="USD → INR rate" wire:model="rate" inputmode="decimal" required placeholder="e.g. 83.25" />
                    <x-ui.input label="Rate source" wire:model="rateSource" required placeholder="e.g. RBI reference rate 20 Oct" />
                    <div class="flex items-end"><x-ui.button type="submit" loading="calculate" icon="check" class="w-full">{{ $selected->status === 'calculated' ? 'Recalculate' : 'Calculate' }}</x-ui.button></div>
                </form>
            @endif

            @if ($canManage && in_array($selected->status, ['calculated', 'crediting', 'failed'], true))
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line bg-surface-2 px-4 py-3">
                    <p class="text-sm text-fg-2">{{ $selected->status === 'failed' ? 'Some credits failed. Retrying credits only what is still owed.' : 'Credits every payable allocation to each player\'s Deposit balance through the wallet ledger.' }}</p>
                    <x-ui.button wire:click="credit" loading="credit" :variant="$selected->status === 'failed' ? 'secondary' : 'primary'" :confirm="'Credit '.$fmtCoins($selected->total_coins_allocated).' coins to '.$selected->eligible_users.' players? Each player is credited at most once for this settlement.'" confirm-title="Credit settlement" confirm-label="Credit now">{{ $selected->status === 'failed' ? 'Retry failed credits' : 'Credit settlement' }}</x-ui.button>
                </div>
            @endif
        </x-ui.card>

        <x-ui.table label="Allocations">
            <x-slot:head><tr><th>Player</th><th class="text-right">Units</th><th class="text-right">Coins</th><th>Status</th><th>Ledger ref</th><th>Credited</th><th>Note</th></tr></x-slot:head>
            @forelse ($allocations as $allocation)
                <tr wire:key="alloc-{{ $allocation->id }}">
                    <td class="mm-cell-primary">{{ $allocation->user?->username ?? '#'.$allocation->user_id }}</td>
                    <td class="text-right tabular-nums">{{ number_format($allocation->units) }}</td>
                    <td class="text-right tabular-nums">{{ $fmtCoins($allocation->coins) }}</td>
                    <td><x-ui.badge :status="$allocation->status === 'credited' ? 'paid' : ($allocation->status === 'below_minimum' ? 'inactive' : $allocation->status)">{{ ucfirst(str_replace('_', ' ', $allocation->status)) }}</x-ui.badge></td>
                    <td class="font-mono text-xs">{{ $allocation->wallet_ledger_entry_id ? 'L'.$allocation->wallet_ledger_entry_id : '—' }}</td>
                    <td class="whitespace-nowrap text-fg-3">{{ $allocation->credited_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td><span class="block max-w-[16rem] truncate text-fg-3" title="{{ $allocation->failure_reason }}">{{ $allocation->failure_reason ?? ($allocation->attempts > 1 ? $allocation->attempts.' attempts' : '') }}</span></td>
                </tr>
            @empty
                <x-ui.table-empty :colspan="7" icon="users" title="No allocations" :description="$selected->status === 'pending' ? 'Calculate the settlement to see each player\'s share.' : 'No verified Ads Earning units in this week.'" />
            @endforelse
            <x-slot:footer>{{ $allocations->links() }}</x-slot:footer>
        </x-ui.table>
    @endif
</div>
