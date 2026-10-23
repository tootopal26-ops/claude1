<div>
    <x-ui.flash />

    @php($base = $placement === 'home' ? 'home-banners' : 'leaderboards.banners')
    @if ($placement === 'home')
        <x-ui.page-header title="Home banners" description="The 16:9 hero carousel at the top of the app's home screen.">
            <x-slot:actions>
                <x-ui.button :href="portal_route($base.'.create')" icon="plus">New banner</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <p class="mb-4 max-w-2xl text-sm text-fg-2">Banners slide across the top of the home screen every five seconds, in the order below. A banner with a link opens it when tapped. Hidden banners stay here but are not shown to players.</p>
    @else
        <x-ui.page-header title="Leaderboard management" description="Rankings, prize rules, payout settlement and the banners shown at the top of the app's leaderboard.">
            <x-slot:actions>
                <x-ui.button :href="portal_route($base.'.create')" icon="plus">New banner</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-leaderboard.subnav current="banners" />

        <p class="mb-4 max-w-2xl text-sm text-fg-2">Banners swipe across the top of the leaderboard screen in the order below. Hidden banners stay here but are not shown to players. A single banner is shown without carousel controls.</p>
    @endif

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th class="w-12">#</th>
                <th>Banner</th>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($banners as $banner)
            <tr wire:key="banner-{{ $banner->id }}">
                <td class="tabular-nums text-fg-3">{{ $banner->display_order }}</td>
                <td class="mm-cell-primary">
                    <div class="flex items-center gap-3">
                        <div class="aspect-video w-36 shrink-0 overflow-hidden rounded-lg border border-line bg-surface-2">
                            @if ($banner->thumbnailAsset?->publicUrl())
                                <img src="{{ $banner->thumbnailAsset->publicUrl() }}" alt="{{ $banner->title ?: 'Leaderboard banner' }}" class="size-full object-cover" loading="lazy">
                            @else
                                <div class="flex size-full items-center justify-center text-fg-3"><x-icon name="image" :size="18" /></div>
                            @endif
                        </div>
                        <span class="block max-w-[20rem] truncate" title="{{ $banner->title }}">{{ $banner->title ?: 'Untitled banner' }}</span>
                    </div>
                </td>
                <td>
                    @if ($banner->is_active)
                        <x-ui.badge variant="success">Visible</x-ui.badge>
                    @else
                        <x-ui.badge variant="neutral">Hidden</x-ui.badge>
                    @endif
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-1">
                        <x-ui.button type="button" variant="ghost" size="sm" icon-only icon="chevron-up" wire:click="moveUp({{ $banner->id }})" aria-label="Move banner {{ $banner->display_order }} earlier" :disabled="$loop->first && $banners->onFirstPage()" />
                        <x-ui.button type="button" variant="ghost" size="sm" icon-only icon="chevron-down" wire:click="moveDown({{ $banner->id }})" aria-label="Move banner {{ $banner->display_order }} later" :disabled="$loop->last && ! $banners->hasMorePages()" />
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggle({{ $banner->id }})" loading="toggle">{{ $banner->is_active ? 'Hide' : 'Show' }}</x-ui.button>
                        <x-ui.button :href="portal_route($base.'.edit', $banner)" variant="ghost" size="sm" icon="edit">Edit</x-ui.button>
                        <x-ui.button type="button" variant="danger-ghost" size="sm" icon="trash" wire:click="delete({{ $banner->id }})" loading="delete" confirm="Delete this banner and its picture permanently?" confirm-title="Delete banner" confirm-label="Delete">Delete</x-ui.button>
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="4" icon="image" title="No banners yet" :description="$placement === 'home' ? 'Add a 16:9 banner to show it at the top of the app\'s home screen.' : 'Add a 16:9 banner to show it at the top of the app\'s leaderboard.'">
                <x-ui.button :href="portal_route($base.'.create')" icon="plus" size="sm">New banner</x-ui.button>
            </x-ui.table-empty>
        @endforelse

        <x-slot:footer>{{ $banners->links() }}</x-slot:footer>
    </x-ui.table>
</div>
