<div>
    <x-ui.page-header title="Players" description="Look up a player to review their wallet, entries and account status." />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="relative w-full sm:max-w-sm">
            <label for="player-search" class="sr-only">Search players by username or mobile number</label>
            <x-icon name="search" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-fg-3" />
            <input
                id="player-search"
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by username or mobile number…"
                autocomplete="off"
                class="mm-control pl-9"
            >
        </div>
        <span class="mm-spinner text-fg-3" wire:loading wire:target="search" role="status"><span class="sr-only">Searching…</span></span>
    </div>

    <x-ui.table wire:loading.class="opacity-60" wire:target="search">
        <x-slot:head>
            <tr>
                <th>Player</th>
                <th>Mobile</th>
                <th>Total balance</th>
                <th>Status</th>
                <th>Joined</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($users as $user)
            <tr wire:key="user-{{ $user->id }}">
                <td class="mm-cell-primary"><span class="block max-w-[16rem] truncate" title="{{ $user->username }}">{{ $user->username }}</span></td>
                <td class="whitespace-nowrap tabular-nums">{{ $user->mobile }}</td>
                <td class="whitespace-nowrap">
                    <span class="inline-flex items-center gap-1.5 tabular-nums text-fg"><x-coin-asset :size="16" />{{ $user->wallet?->totalBalance() ?? '0.00' }}</span>
                </td>
                <td>
                    @if ($user->is_banned)
                        <x-ui.badge status="banned" />
                    @else
                        <x-ui.badge status="active" />
                    @endif
                </td>
                <td class="whitespace-nowrap">{{ $user->created_at->format('d M Y') }}</td>
                <td class="text-right">
                    <x-ui.button :href="portal_route('users.show', $user)" variant="ghost" size="sm" icon="user">View profile</x-ui.button>
                </td>
            </tr>
        @empty
            @if ($search !== '')
                <x-ui.table-empty :colspan="6" icon="search" title="No players match your search" :description="'Nothing matched “'.$search.'”. Check the spelling or try part of the username or mobile number.'">
                    <x-ui.button variant="outline" size="sm" wire:click="$set('search', '')">Clear search</x-ui.button>
                </x-ui.table-empty>
            @else
                <x-ui.table-empty :colspan="6" icon="users" title="No players yet" description="Players appear here once they register in the app." />
            @endif
        @endforelse

        <x-slot:footer>{{ $users->links() }}</x-slot:footer>
    </x-ui.table>
</div>
