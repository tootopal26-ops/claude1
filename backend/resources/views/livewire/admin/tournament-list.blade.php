<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-violet-200">Tournaments</h1>
        <a href="{{ route('admin.tournaments.create') }}"
            class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
            + Create tournament
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Title</th>
                    <th class="px-4 py-3">Game</th>
                    <th class="px-4 py-3">Mode</th>
                    <th class="px-4 py-3">Entry fee</th>
                    <th class="px-4 py-3">Slots</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Starts</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($tournaments as $tournament)
                    <tr>
                        <td class="px-4 py-3 font-medium text-white">{{ $tournament->title }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $tournament->game->name }}</td>
                        <td class="px-4 py-3 uppercase text-violet-300">{{ $tournament->mode }}</td>
                        <td class="px-4 py-3">
                            @if ($tournament->isFree())
                                <span class="text-emerald-400">Free</span>
                            @else
                                {{ $tournament->entry_fee }} coins
                            @endif
                        </td>
                        <td class="px-4 py-3 text-violet-300">{{ $tournament->joined_count }}/{{ $tournament->total_slots }}</td>
                        <td class="px-4 py-3 capitalize text-violet-300">{{ $tournament->status }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $tournament->scheduled_start_at->format('d M, H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.tournaments.entries', $tournament) }}" class="text-violet-400 hover:text-violet-200">
                                View entries
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-violet-400">No tournaments yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tournaments->links() }}</div>
</div>
