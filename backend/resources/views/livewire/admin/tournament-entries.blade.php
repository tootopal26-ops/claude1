<div>
    <a href="{{ route('admin.tournaments.index') }}" class="text-sm text-violet-400 hover:text-violet-200">&larr; Back to tournaments</a>

    <h1 class="mb-1 mt-2 text-2xl font-bold text-violet-200">{{ $tournament->title }}</h1>
    <p class="mb-6 text-sm text-violet-400">
        {{ $entries->count() }}/{{ $tournament->total_slots }} joined ·
        {{ $tournament->isFree() ? 'Free entry' : $tournament->entry_fee.' coins' }}
    </p>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">Account</th>
                    <th class="px-4 py-3">In-game name</th>
                    <th class="px-4 py-3">UID</th>
                    <th class="px-4 py-3">Joined at</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($entries as $index => $entry)
                    <tr>
                        <td class="px-4 py-3 text-violet-300">{{ $index + 1 }}</td>
                        <td class="px-4 py-3 text-white">{{ $entry->user->username }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $entry->players->first()?->in_game_name }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $entry->players->first()?->uid ?? '—' }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $entry->created_at->format('d M, H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-violet-400">No one has joined yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
