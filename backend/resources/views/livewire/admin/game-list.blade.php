<div>
    <x-ui.flash />
    @error('delete')
        <x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.page-header title="Tournament games" description="The cards on the app's home screen, in the order players see them. Disabled cards stay here but disappear from the app.">
        <x-slot:actions>
            <x-ui.button :href="portal_route('tournament-games.create')" icon="plus">New game card</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 max-w-sm">
        <x-ui.input type="search" label="Search games" wire:model.live.debounce.300ms="search" placeholder="Search by title" />
    </div>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th class="w-12">#</th>
                <th>Card</th>
                <th>Status</th>
                <th>Tournaments</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($games as $game)
            <tr wire:key="game-{{ $game->id }}">
                <td class="tabular-nums text-fg-3">{{ $game->display_order }}</td>
                <td class="mm-cell-primary">
                    <div class="flex items-center gap-3">
                        <div class="aspect-video w-28 shrink-0 overflow-hidden rounded-lg border border-line bg-surface-2">
                            @if ($game->thumbnailAsset?->publicUrl())
                                <img src="{{ $game->thumbnailAsset->publicUrl() }}" alt="{{ $game->name }} card" class="size-full object-cover" loading="lazy">
                            @else
                                <div class="flex size-full items-center justify-center text-fg-3"><x-icon name="tournaments" :size="18" /></div>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <span class="block max-w-[18rem] truncate" title="{{ $game->name }}">{{ $game->name }}</span>
                            @if ($game->description)
                                <span class="block max-w-[22rem] truncate text-xs font-normal text-fg-3" title="{{ $game->description }}">{{ $game->description }}</span>
                            @endif
                        </div>
                    </div>
                </td>
                <td>
                    @if ($game->is_active)
                        <x-ui.badge variant="success">Visible</x-ui.badge>
                    @else
                        <x-ui.badge variant="neutral">Hidden</x-ui.badge>
                    @endif
                </td>
                <td class="tabular-nums text-fg">{{ $game->tournaments_count }}</td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-1">
                        @if (trim($search) === '')
                            <x-ui.button type="button" variant="ghost" size="sm" icon-only icon="chevron-up" wire:click="moveUp({{ $game->id }})" aria-label="Move {{ $game->name }} earlier" :disabled="$loop->first && $games->onFirstPage()" />
                            <x-ui.button type="button" variant="ghost" size="sm" icon-only icon="chevron-down" wire:click="moveDown({{ $game->id }})" aria-label="Move {{ $game->name }} later" :disabled="$loop->last && ! $games->hasMorePages()" />
                        @endif
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggle({{ $game->id }})" loading="toggle">{{ $game->is_active ? 'Hide' : 'Show' }}</x-ui.button>
                        <x-ui.button :href="portal_route('tournament-games.edit', $game)" variant="ghost" size="sm" icon="edit">Edit</x-ui.button>
                        <x-ui.button type="button" variant="danger-ghost" size="sm" icon="trash" wire:click="delete({{ $game->id }})" loading="delete" confirm="Delete &quot;{{ $game->name }}&quot; and its picture permanently? Players will no longer see this card." confirm-title="Delete game card" confirm-label="Delete">Delete</x-ui.button>
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="5" icon="tournaments" :title="trim($search) !== '' ? 'No games match your search' : 'No game cards yet'" :description="trim($search) !== '' ? 'Try a different title.' : 'Add a card to show a game on the app\'s home screen.'">
                @if (trim($search) === '')
                    <x-ui.button :href="portal_route('tournament-games.create')" icon="plus" size="sm">New game card</x-ui.button>
                @endif
            </x-ui.table-empty>
        @endforelse

        <x-slot:footer>{{ $games->links() }}</x-slot:footer>
    </x-ui.table>
</div>
