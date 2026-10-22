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

    @if (auth('staff')->user()->hasPermission('results.upload_attachments'))
        <div class="mt-8 rounded-2xl border border-violet-900/40 p-4">
            <h2 class="mb-3 text-lg font-semibold text-violet-200">Result evidence</h2>

            @if ($evidenceError)
                <div class="mb-3 rounded-lg bg-red-900/40 px-4 py-3 text-sm text-red-300">{{ $evidenceError }}</div>
            @endif

            <form wire:submit="uploadEvidence" class="mb-4 flex items-center gap-3">
                <input type="file" wire:model="evidenceFile" class="text-sm text-violet-300">
                <button type="submit" class="rounded-lg bg-violet-700 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-600" wire:loading.attr="disabled" wire:target="evidenceFile,uploadEvidence">
                    Upload
                </button>
                @error('evidenceFile') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
            </form>

            <ul class="divide-y divide-violet-900/30 text-sm">
                @forelse ($this->evidence as $asset)
                    <li class="flex items-center justify-between py-2">
                        <div>
                            <a href="{{ route('admin.storage-assets.download', $asset) }}" class="text-violet-300 hover:text-violet-100">{{ $asset->original_filename }}</a>
                            <span class="ml-2 text-xs text-violet-500">{{ number_format($asset->size_bytes / 1024, 0) }} KB &middot; {{ $asset->created_at->diffForHumans() }}</span>
                            @if ($asset->isRetained())
                                <span class="ml-2 rounded bg-amber-900/40 px-2 py-0.5 text-xs text-amber-300">Retained: {{ $asset->retained_reason }}</span>
                            @endif
                        </div>
                        <button type="button" wire:click="deleteEvidence({{ $asset->id }})" wire:confirm="Delete this evidence file permanently?" class="text-xs text-red-400 hover:text-red-200">
                            Delete
                        </button>
                    </li>
                @empty
                    <li class="py-2 text-violet-500">No evidence uploaded yet.</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
