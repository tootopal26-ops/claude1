<div>
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Leaderboards</h1>

    @if ($actionStatus)
        <div class="mb-4 rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ $actionStatus }}</div>
    @endif

    <div class="mb-6 flex items-center justify-between">
        <div class="flex gap-2">
            @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $value => $label)
                <button type="button" wire:click="$set('type', '{{ $value }}')"
                    class="rounded-lg px-4 py-2 text-sm font-semibold {{ $type === $value ? 'bg-violet-600 text-white' : 'bg-white/5 text-violet-300' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex gap-3">
            <button type="button" wire:click="recomputeNow" class="text-sm text-violet-400 hover:text-violet-200">Recompute now</button>
            @if ($period->status === 'provisional')
                <button type="button" wire:click="finalizeNow" wire:confirm="Finalize this period and pay out any configured rewards now?" class="text-sm text-red-400 hover:text-red-300">Finalize now</button>
            @endif
        </div>
    </div>

    <p class="mb-4 text-sm text-violet-400">
        {{ $period->period_start->format('d M Y, H:i') }} &ndash; {{ $period->period_end->format('d M Y, H:i') }} (UTC)
        · status: {{ ucfirst($period->status) }}
        @if ($period->finalized_at)
            · finalized {{ $period->finalized_at->format('d M, H:i') }}
        @endif
    </p>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Rank</th>
                    <th class="px-4 py-3">Player</th>
                    <th class="px-4 py-3">Score</th>
                    <th class="px-4 py-3">Kills</th>
                    <th class="px-4 py-3">Tournaments</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($entries as $entry)
                    <tr>
                        <td class="px-4 py-3 text-violet-300">#{{ $entry->rank }}</td>
                        <td class="px-4 py-3 text-white">{{ $entry->user->username }}</td>
                        <td class="px-4 py-3 text-emerald-400">{{ $entry->score }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $entry->total_kills }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $entry->tournaments_played }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-violet-400">No entries for this period yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
