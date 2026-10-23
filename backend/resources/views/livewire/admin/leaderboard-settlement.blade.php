<div>
    <x-ui.flash />

    <x-ui.page-header title="Leaderboard management" description="Rankings, prize rules, payout settlement and the banners shown at the top of the app's leaderboard." />

    <x-leaderboard.subnav current="settlement" />

    <x-ui.alert variant="info" class="mb-6" title="How settlement works">
        When a period ends it is finalized: its ranking is locked and its prizes are frozen. Each prize is then paid once, as winnings, to the player holding that rank.
        This page compares what each period should pay with what has been paid. Retrying only completes missing payouts — it can never pay a rank twice.
    </x-ui.alert>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Period</th>
                <th>Finalized</th>
                <th class="text-right">Prized ranks</th>
                <th class="text-right">Paid</th>
                <th class="text-right">Pending</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($rows as $row)
            @php($period = $row['period'])
            <tr wire:key="period-{{ $period->id }}">
                <td class="mm-cell-primary">
                    <x-ui.badge variant="brand" :dot="false">{{ ucfirst($period->type) }}</x-ui.badge>
                    <span class="mt-1 block text-xs font-normal text-fg-3">{{ $period->period_start->timezone('Asia/Kolkata')->format('d M Y') }} – {{ $period->period_end->timezone('Asia/Kolkata')->subSecond()->format('d M Y') }} (IST)</span>
                </td>
                <td class="whitespace-nowrap text-xs">{{ $period->finalized_at?->format('d M Y, H:i') }} <span class="text-fg-3">UTC</span></td>
                <td class="text-right tabular-nums">{{ count($row['prizes']) }}</td>
                <td class="whitespace-nowrap text-right tabular-nums text-fg">
                    {{ $row['paid_count'] }}
                    <span class="inline-flex items-center gap-1 text-xs text-fg-3"><x-coin-asset :size="12" />{{ number_format($row['paid_coins']) }}</span>
                </td>
                <td class="text-right">
                    @if ($row['pending']->isEmpty())
                        <x-ui.badge variant="success">None</x-ui.badge>
                    @else
                        <x-ui.badge variant="warning">{{ $row['pending']->count() }}</x-ui.badge>
                    @endif
                </td>
                <td class="text-right">
                    @if ($row['pending']->isNotEmpty())
                        <x-ui.button
                            type="button"
                            variant="outline"
                            size="sm"
                            wire:click="retry({{ $period->id }})"
                            loading="retry"
                            confirm="Retry the {{ $row['pending']->count() }} pending payout(s) for this {{ $period->type }} period? Ranks that were already paid are skipped."
                            confirm-title="Retry pending payouts"
                            confirm-label="Retry payouts"
                        >Retry pending</x-ui.button>
                    @endif
                </td>
            </tr>
            @if ($row['pending']->isNotEmpty())
                <tr wire:key="pending-{{ $period->id }}" class="bg-surface-2/40">
                    <td colspan="6" class="px-4 py-3">
                        <p class="mb-2 text-xs font-medium text-fg-2">Pending payouts</p>
                        <ul class="grid gap-1.5 text-sm sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($row['pending'] as $item)
                                <li class="flex items-center justify-between gap-3 rounded-lg border border-line bg-surface px-3 py-2">
                                    <span class="min-w-0 truncate"><span class="tabular-nums text-fg-3">#{{ $item['rank'] }}</span> <span class="text-fg">{{ $item['username'] }}</span></span>
                                    <span class="shrink-0 text-right text-xs">
                                        <span class="block tabular-nums text-fg">{{ number_format($item['coins']) }} coins</span>
                                        <span class="block text-warning">{{ $item['reason'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
            @endif
        @empty
            <x-ui.table-empty :colspan="6" icon="leaderboard" title="No finalized periods yet" description="Periods appear here once they have ended and been finalized." />
        @endforelse
    </x-ui.table>
</div>
