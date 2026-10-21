<div>
    <a href="{{ route('admin.tournaments.entries', $tournament) }}" class="text-sm text-violet-400 hover:text-violet-200">&larr; Back to entries</a>

    <h1 class="mb-1 mt-2 text-2xl font-bold text-violet-200">{{ $tournament->title }} — Results</h1>
    <p class="mb-6 text-sm text-violet-400">
        Prize pool: {{ $tournament->prize_pool }} coins · status: {{ $tournament->status }}
    </p>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    @if ($submitError)
        <div class="mb-4 rounded-lg bg-red-900/40 px-4 py-3 text-sm text-red-300">{{ $submitError }}</div>
    @endif

    <form wire:submit="submit">
        <div class="overflow-hidden rounded-2xl border border-violet-900/40">
            <table class="w-full text-left text-sm">
                <thead class="bg-white/5 text-violet-300">
                    <tr>
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3">Rank</th>
                        <th class="px-4 py-3">Kills</th>
                        <th class="px-4 py-3">Prize awarded (coins)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-violet-900/30">
                    @forelse ($rows as $index => $row)
                        <tr>
                            <td class="px-4 py-3 text-white">{{ $row['username'] }}</td>
                            <td class="px-4 py-3">
                                <input type="number" min="1" wire:model="rows.{{ $index }}.rank" class="w-20 rounded-lg border border-violet-800/50 bg-black/30 px-2 py-1 text-white">
                                @error("rows.{$index}.rank") <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                            </td>
                            <td class="px-4 py-3">
                                <input type="number" min="0" wire:model="rows.{{ $index }}.kills" class="w-20 rounded-lg border border-violet-800/50 bg-black/30 px-2 py-1 text-white">
                                @error("rows.{$index}.kills") <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                            </td>
                            <td class="px-4 py-3">
                                <input type="number" step="0.01" min="0" wire:model="rows.{{ $index }}.prize_awarded" class="w-28 rounded-lg border border-violet-800/50 bg-black/30 px-2 py-1 text-white">
                                @error("rows.{$index}.prize_awarded") <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-violet-400">No confirmed entries to score.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (count($rows) > 0)
            <button type="submit" wire:confirm="Save these results? Prize changes affect player wallets immediately." class="mt-4 w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
                Save results
            </button>
        @endif
    </form>
</div>
