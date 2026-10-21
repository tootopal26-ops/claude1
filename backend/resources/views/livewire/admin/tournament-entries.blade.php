<div>
    <a href="{{ route('admin.tournaments.index') }}" class="text-sm text-violet-400 hover:text-violet-200">&larr; Back to tournaments</a>

    <h1 class="mb-1 mt-2 text-2xl font-bold text-violet-200">{{ $tournament->title }}</h1>
    <p class="mb-6 text-sm text-violet-400">
        {{ ucfirst($tournament->mode) }} · {{ $entries->count() }}/{{ $tournament->total_slots }} teams joined ·
        {{ $tournament->isFree() ? 'Free entry' : $tournament->entry_fee.' coins' }}
        @if ($tournament->min_players)
            · min {{ $tournament->min_players }} teams
        @endif
        · status: {{ $tournament->status }}
    </p>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    @if (auth('staff')->user()->hasPermission('credentials.release'))
        <div class="mb-6 rounded-2xl border border-violet-900/40 bg-white/5 p-6">
            <h2 class="mb-4 text-lg font-bold text-violet-200">Room credentials</h2>

            @if ($tournament->credentials_released_at)
                <p class="text-sm text-emerald-400">
                    Released {{ $tournament->credentials_released_at->format('d M, H:i') }}
                    by {{ $tournament->credential?->releasedByStaff?->display_name ?? 'staff' }}.
                </p>
                <p class="mt-2 text-sm text-violet-300">Room ID: <span class="text-white">{{ $tournament->credential?->room_id }}</span></p>
                <p class="text-sm text-violet-300">Password: <span class="text-white">{{ $tournament->credential?->room_password }}</span></p>
            @else
                <form wire:submit="releaseCredentials" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-xs text-violet-300">Room ID</label>
                            <input type="text" wire:model="roomId" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                            @error('roomId') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-violet-300">Password</label>
                            <input type="text" wire:model="roomPassword" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                            @error('roomPassword') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($releaseError)
                        <p class="text-sm text-red-400">{{ $releaseError }}</p>
                    @endif

                    <button type="submit" wire:confirm="Release room credentials to every joined player now?" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
                        Release to joined players
                    </button>
                </form>
            @endif
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">Account</th>
                    <th class="px-4 py-3">Players (IGN / UID)</th>
                    <th class="px-4 py-3">Joined at</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($entries as $index => $entry)
                    <tr>
                        <td class="px-4 py-3 text-violet-300">{{ $index + 1 }}</td>
                        <td class="px-4 py-3 text-white">{{ $entry->user->username }}</td>
                        <td class="px-4 py-3 text-violet-300">
                            @foreach ($entry->players->sortBy('slot_number') as $player)
                                <div>{{ $player->in_game_name }} <span class="text-violet-500">({{ $player->uid ?? '—' }})</span></div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 text-violet-300">{{ $entry->created_at->format('d M, H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-violet-400">No one has joined yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
