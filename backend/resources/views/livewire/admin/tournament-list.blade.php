<div>
    <x-ui.flash />

    <x-ui.page-header title="Tournaments" description="Every scheduled, live and finished tournament, with how full each one is.">
        <x-slot:actions>
            @if (auth('staff')->user()->hasPermission('tournaments.manage'))
                <x-ui.button :href="route('admin.tournaments.create')" icon="plus">Create tournament</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Tournament</th>
                <th>Mode</th>
                <th>Entry fee</th>
                <th>Slots</th>
                <th>Status</th>
                <th>Starts</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($tournaments as $tournament)
            @php($fill = $tournament->total_slots > 0 ? min(100, round($tournament->joined_count / $tournament->total_slots * 100)) : 0)
            <tr wire:key="tournament-{{ $tournament->id }}">
                <td class="mm-cell-primary">
                    <span class="block max-w-[18rem] truncate" title="{{ $tournament->title }}">{{ $tournament->title }}</span>
                    <span class="block text-xs font-normal text-fg-3">{{ $tournament->game->name }}</span>
                </td>
                <td class="uppercase">{{ $tournament->mode }}</td>
                <td class="whitespace-nowrap">
                    @if ($tournament->isFree())
                        <x-ui.badge variant="success" :dot="false">Free</x-ui.badge>
                    @else
                        <span class="inline-flex items-center gap-1.5 tabular-nums text-fg"><x-coin-asset :size="16" />{{ $tournament->entry_fee }}</span>
                    @endif
                </td>
                <td>
                    <div class="flex items-center gap-3">
                        <span class="w-14 text-xs tabular-nums text-fg">{{ $tournament->joined_count }}/{{ $tournament->total_slots }}</span>
                        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-surface-3" role="img" aria-label="{{ $fill }}% full">
                            <div class="h-full rounded-full bg-brand" style="width: {{ $fill }}%"></div>
                        </div>
                    </div>
                </td>
                <td><x-ui.badge :status="$tournament->status" /></td>
                <td class="whitespace-nowrap">{{ $tournament->scheduled_start_at->format('d M, H:i') }}</td>
                <td class="text-right">
                    <x-ui.button :href="route('admin.tournaments.entries', $tournament)" variant="ghost" size="sm" icon="users">View entries</x-ui.button>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="7" icon="tournaments" title="No tournaments yet" description="Tournaments you schedule will be listed here with their entries and results.">
                @if (auth('staff')->user()->hasPermission('tournaments.manage'))
                    <x-ui.button :href="route('admin.tournaments.create')" icon="plus" size="sm">Create tournament</x-ui.button>
                @endif
            </x-ui.table-empty>
        @endforelse

        <x-slot:footer>{{ $tournaments->links() }}</x-slot:footer>
    </x-ui.table>
</div>
