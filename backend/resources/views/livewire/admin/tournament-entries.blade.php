@php
    $canSubmitResults = auth('staff')->user()->hasPermission('results.submit');
    $joined = $entries->count();
    $fill = $tournament->total_slots > 0 ? min(100, round($joined / $tournament->total_slots * 100)) : 0;
@endphp

<div>
    <x-ui.flash />

    <x-ui.page-header
        :title="$tournament->title"
        :description="ucfirst($tournament->mode).' tournament · starts '.$tournament->scheduled_start_at->format('d M Y, H:i')"
        :breadcrumbs="[
            ['label' => 'Tournaments', 'url' => portal_route('tournaments.index')],
            ['label' => $tournament->title],
        ]"
    >
        <x-slot:actions>
            <x-ui.badge :status="$tournament->status" class="px-3 py-1 text-sm" />
            @if ($canSubmitResults)
                <x-ui.button :href="portal_route('tournaments.results', $tournament)" variant="outline" icon="leaderboard">Enter / view results</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Overview">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.kv label="Teams joined">
                <span class="text-lg font-semibold tabular-nums">{{ $joined }}/{{ $tournament->total_slots }}</span>
                <div class="mt-2 h-1.5 w-full max-w-[10rem] overflow-hidden rounded-full bg-surface-3" role="img" aria-label="{{ $fill }}% full">
                    <div class="h-full rounded-full bg-brand" style="width: {{ $fill }}%"></div>
                </div>
            </x-ui.kv>
            <x-ui.kv label="Entry fee">
                @if ($tournament->isFree())
                    <x-ui.badge variant="success" :dot="false">Free entry</x-ui.badge>
                @else
                    <span class="inline-flex items-center gap-1.5 text-lg font-semibold tabular-nums"><x-coin-asset :size="18" />{{ $tournament->entry_fee }}</span>
                    <span class="block text-xs text-fg-3">per team</span>
                @endif
            </x-ui.kv>
            <x-ui.kv label="Minimum teams">
                @if ($tournament->min_players)
                    <span class="text-lg font-semibold tabular-nums">{{ $tournament->min_players }}</span>
                    <span class="block text-xs text-fg-3">Cancelled and refunded if not met</span>
                @else
                    <span class="text-fg-2">No minimum</span>
                @endif
            </x-ui.kv>
            <x-ui.kv label="Prize pool"><span class="inline-flex items-center gap-1.5 text-lg font-semibold tabular-nums"><x-coin-asset :size="18" />{{ $tournament->prize_pool }}</span></x-ui.kv>
        </dl>
    </x-ui.card>

    @if (auth('staff')->user()->hasPermission('credentials.release'))
        <x-ui.card title="Room credentials" description="Shared with every joined player at the moment you release them." class="mt-6">
            @if ($tournament->credentials_released_at)
                <x-ui.alert variant="success" title="Credentials released">
                    {{ $tournament->credentials_released_at->format('d M, H:i') }}
                    by {{ $tournament->credential?->releasedByStaff?->display_name ?? 'staff' }}.
                </x-ui.alert>

                <dl class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.kv label="Room ID"><span class="font-mono text-base">{{ $tournament->credential?->room_id }}</span></x-ui.kv>
                    <x-ui.kv label="Password"><span class="font-mono text-base">{{ $tournament->credential?->room_password }}</span></x-ui.kv>
                </dl>
            @elseif (! in_array($tournament->status, ['upcoming', 'ongoing'], true))
                <x-ui.alert variant="info" title="Credentials can't be released">Room credentials can only be released for an upcoming or ongoing tournament. This one is {{ $tournament->status }}.</x-ui.alert>
            @else
                <form wire:submit="releaseCredentials" class="space-y-5" novalidate>
                    @if ($releaseError)
                        <x-ui.alert variant="danger" title="Credentials weren't released">{{ $releaseError }}</x-ui.alert>
                    @endif

                    <div class="grid grid-cols-1 gap-x-4 gap-y-5 sm:grid-cols-2">
                        <x-ui.input label="Room ID" wire:model="roomId" required autocomplete="off" hint="The custom-room ID players enter in the game." />
                        <x-ui.input label="Password" wire:model="roomPassword" required autocomplete="off" hint="The custom-room password." />
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-fg-3">Releasing closes joining, starts the match (even if the minimum isn't met) and notifies every joined player. It can only be done once.</p>
                        <x-ui.button type="submit" icon="check" loading="releaseCredentials" confirm="Release room credentials to every joined player now?" confirm-title="Release room credentials" confirm-label="Release">Release to joined players</x-ui.button>
                    </div>
                </form>
            @endif
        </x-ui.card>
    @endif

    <h2 class="mb-3 mt-8 text-sm font-semibold text-fg">Joined teams</h2>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th class="w-12">#</th>
                <th>Account</th>
                <th>Players (IGN / UID)</th>
                <th>Joined at</th>
            </tr>
        </x-slot:head>

        @forelse ($entries as $index => $entry)
            <tr wire:key="entry-{{ $entry->id }}">
                <td class="tabular-nums text-fg-3">{{ $index + 1 }}</td>
                <td class="mm-cell-primary">
                    @if (auth('staff')->user()->hasPermission('users.view'))
                        <a href="{{ portal_route('users.show', $entry->user) }}" class="hover:text-brand-text">{{ $entry->user->username }}</a>
                    @else
                        {{ $entry->user->username }}
                    @endif
                </td>
                <td>
                    <ul class="space-y-0.5">
                        @foreach ($entry->players->sortBy('slot_number') as $player)
                            <li class="flex flex-wrap items-baseline gap-x-2">
                                <span class="max-w-[14rem] truncate text-fg" title="{{ $player->in_game_name }}">{{ $player->in_game_name }}</span>
                                <span class="font-mono text-xs text-fg-3">({{ $player->uid ?? '—' }})</span>
                            </li>
                        @endforeach
                    </ul>
                </td>
                <td class="whitespace-nowrap">{{ $entry->created_at->format('d M, H:i') }}</td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="4" icon="users" title="No one has joined yet" description="Teams that join this tournament will be listed here with their in-game names." />
        @endforelse
    </x-ui.table>
</div>
