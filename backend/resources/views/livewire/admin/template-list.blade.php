<div>
    <x-ui.flash />

    <x-ui.page-header title="Recurring Templates" description="Templates generate tournaments automatically on a schedule. Pause one to stop new tournaments without touching existing ones.">
        <x-slot:actions>
            <x-ui.button :href="portal_route('templates.create')" icon="plus">New template</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($generateStatus)
        <x-ui.alert variant="success" title="Generation finished" class="mb-6">{{ $generateStatus }}</x-ui.alert>
    @endif

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Template</th>
                <th>Recurrence</th>
                <th>Generated</th>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($templates as $template)
            <tr wire:key="template-{{ $template->id }}">
                <td class="mm-cell-primary">
                    <span class="block max-w-[18rem] truncate" title="{{ $template->title }}">{{ $template->title }}</span>
                    <span class="block text-xs font-normal text-fg-3">{{ $template->game->name }}</span>
                </td>
                <td class="whitespace-nowrap">
                    @if ($template->frequency === 'weekly')
                        Weekly · {{ $template->dayOfWeekLabel() }} {{ \Illuminate\Support\Carbon::parse($template->time_of_day)->format('H:i') }}
                    @else
                        Daily · {{ \Illuminate\Support\Carbon::parse($template->time_of_day)->format('H:i') }}
                    @endif
                </td>
                <td class="tabular-nums">{{ $template->generated_tournaments_count }}</td>
                <td>
                    @if ($template->is_active)
                        <x-ui.badge variant="success">Active</x-ui.badge>
                    @else
                        <x-ui.badge variant="neutral">Paused</x-ui.badge>
                    @endif
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-1">
                        <x-ui.button type="button" variant="ghost" size="sm" icon="calendar" wire:click="generateNow({{ $template->id }})" loading="generateNow({{ $template->id }})" confirm="Generate the upcoming tournaments for this template now? Occurrences that already exist are skipped." confirm-title="Generate tournaments" confirm-label="Generate now">Generate now</x-ui.button>
                        <x-ui.button :href="portal_route('templates.edit', $template)" variant="outline" size="sm" icon="edit">Edit</x-ui.button>
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="5" icon="calendar" title="No recurring templates yet" description="Create a template to have daily or weekly tournaments generated for you.">
                <x-ui.button :href="portal_route('templates.create')" icon="plus" size="sm">New template</x-ui.button>
            </x-ui.table-empty>
        @endforelse
    </x-ui.table>
</div>
