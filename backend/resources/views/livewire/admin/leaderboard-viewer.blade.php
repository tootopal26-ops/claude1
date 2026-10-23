@php
    $payouts = $period->rewardPayouts()->get()->keyBy('user_id');
    $isFinalized = $period->status !== 'provisional';
@endphp

<div>
    <x-ui.page-header title="Leaderboards" description="Rankings are recomputed from published results. Finalizing a period pays out its rewards.">
        <x-slot:actions>
            <x-ui.button variant="outline" icon="clock" wire:click="recomputeNow" loading="recomputeNow">Recompute now</x-ui.button>
            @if (! $isFinalized)
                <x-ui.button
                    variant="danger"
                    icon="check"
                    wire:click="finalizeNow"
                    loading="finalizeNow"
                    confirm="Finalize this period and pay out any configured rewards now?"
                    confirm-title="Finalize period"
                    confirm-label="Finalize and pay out"
                >Finalize now</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($actionStatus)
        <x-ui.alert variant="success" class="mb-6">{{ $actionStatus }}</x-ui.alert>
    @endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
        <x-ui.tabs label="Leaderboard period">
            @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $value => $label)
                <x-ui.tab :active="$type === $value" wire:click="$set('type', '{{ $value }}')">{{ $label }}</x-ui.tab>
            @endforeach
        </x-ui.tabs>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-fg-2">
            <span>{{ $period->period_start->format('d M Y, H:i') }} &ndash; {{ $period->period_end->format('d M Y, H:i') }} <span class="text-fg-3">(UTC)</span></span>
            <x-ui.badge :variant="$isFinalized ? 'success' : 'warning'">{{ ucfirst($period->status) }}</x-ui.badge>
            @if ($period->finalized_at)
                <span class="text-xs text-fg-3">Finalized {{ $period->finalized_at->format('d M, H:i') }}</span>
            @endif
        </div>
    </div>

    <x-ui.table wire:loading.class="opacity-60">
        <x-slot:head>
            <tr>
                <th class="w-20">Rank</th>
                <th>Player</th>
                <th class="text-right">Score</th>
                <th class="text-right">Kills</th>
                <th class="text-right">Tournaments</th>
                <th class="text-right">Reward</th>
            </tr>
        </x-slot:head>

        @forelse ($entries as $entry)
            @php($payout = $payouts->get($entry->user_id))
            <tr wire:key="entry-{{ $entry->id }}">
                <td>
                    @if ($entry->rank <= 3)
                        <x-ui.badge variant="brand" :dot="false" class="tabular-nums">#{{ $entry->rank }}</x-ui.badge>
                    @else
                        <span class="tabular-nums">#{{ $entry->rank }}</span>
                    @endif
                </td>
                <td class="mm-cell-primary"><span class="block max-w-[16rem] truncate" title="{{ $entry->user->username }}">{{ $entry->user->username }}</span></td>
                <td class="text-right font-medium tabular-nums text-fg">{{ $entry->score }}</td>
                <td class="text-right tabular-nums">{{ $entry->total_kills }}</td>
                <td class="text-right tabular-nums">{{ $entry->tournaments_played }}</td>
                <td class="whitespace-nowrap text-right">
                    @if ($payout)
                        <span class="inline-flex items-center gap-1.5 tabular-nums text-success"><x-coin-asset :size="14" />{{ $payout->amount }}</span>
                    @else
                        <span class="text-fg-3" title="{{ $isFinalized ? 'No reward was paid for this rank' : 'Rewards are paid when the period is finalized' }}">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="6" icon="leaderboard" title="No entries for this period yet" description="Players appear here once tournaments in this period have published results." />
        @endforelse
    </x-ui.table>
</div>
