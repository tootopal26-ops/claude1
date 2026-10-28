<div>
    <x-ui.flash />

    <x-ui.page-header title="Tournament tickets" description="Free tournaments can ask each player to watch rewarded ads first — one verified ad per ticket. Earning every ticket only unlocks Join; it never registers anyone." />

    @include('livewire.admin.ads._nav')

    @unless ($placementLive)
        <x-ui.alert variant="warning" class="mb-4">The "Tournament ticket" placement isn't live, so players can't earn tickets right now and free tournaments that need tickets can't be joined. Switch it on under Placements, or set their tickets to 0.</x-ui.alert>
    @endunless

    <form wire:submit="save" novalidate>
        @error('values')<x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror
        <x-ui.form-section title="Defaults" description="Used by the Create tournament form when Free is chosen.">
            @include('livewire.admin.ads._setting', ['key' => 'tickets_default_free', 'hint' => 'Default 1.'])
            @include('livewire.admin.ads._setting', ['key' => 'tickets_max_per_tournament'])
        </x-ui.form-section>
        <div class="mb-6 flex justify-end"><x-ui.button type="submit" loading="save" icon="check">Save defaults</x-ui.button></div>
    </form>

    <h2 class="mb-2 text-sm font-semibold text-fg">Upcoming free tournaments</h2>
    <x-ui.table label="Free tournaments and tickets">
        <x-slot:head><tr><th>Tournament</th><th>Starts</th><th class="text-right">Joined</th><th class="text-right">Players earning</th><th class="text-right">Tickets complete</th><th>Tickets required</th></tr></x-slot:head>
        @forelse ($tournaments as $tournament)
            @php($holders = $tickets[$tournament->id] ?? collect())
            <tr wire:key="tt-{{ $tournament->id }}">
                <td class="mm-cell-primary">{{ $tournament->title }}</td>
                <td class="whitespace-nowrap">{{ $tournament->scheduled_start_at->format('d M H:i') }}</td>
                <td class="text-right tabular-nums">{{ $tournament->entries_count }}/{{ $tournament->total_slots }}</td>
                <td class="text-right tabular-nums">{{ $holders->count() }}</td>
                <td class="text-right tabular-nums">{{ $tournament->required_ad_tickets > 0 ? $holders->where('held', '>=', $tournament->required_ad_tickets)->count() : '—' }}</td>
                <td>
                    <form wire:submit="saveTournament({{ $tournament->id }})" class="flex items-start gap-2" novalidate>
                        <x-ui.input type="number" min="0" max="{{ config('minimax.tickets_max_per_tournament') }}" wire:model="required.{{ $tournament->id }}" aria-label="Tickets required for {{ $tournament->title }}" class="w-20" />
                        <x-ui.button type="submit" size="sm" variant="secondary" loading="saveTournament({{ $tournament->id }})">Save</x-ui.button>
                    </form>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="6" icon="tournaments" title="No upcoming free tournaments" />
        @endforelse
    </x-ui.table>

    @include('livewire.admin.ads._history')
</div>
