<div>
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Storage</h1>

    @if ($actionError)
        <div class="mb-4 rounded-lg bg-red-900/40 px-4 py-3 text-sm text-red-300">{{ $actionError }}</div>
    @endif

    <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ($usage as $bucketUsage)
            <div class="rounded-2xl border border-violet-900/40 p-4">
                <p class="text-sm text-violet-300">{{ $bucketUsage['label'] }}</p>
                <p class="mt-1 text-2xl font-bold text-white">{{ number_format($bucketUsage['total_bytes'] / 1048576, 1) }} MB</p>
                <p class="text-xs text-violet-500">{{ $bucketUsage['count'] }} file(s)</p>
                @php
                    $percent = $bucketUsage['quota_bytes'] > 0 ? min(100, (int) round($bucketUsage['total_bytes'] / $bucketUsage['quota_bytes'] * 100)) : 0;
                @endphp
                <div class="mt-2 h-1.5 w-full rounded-full bg-violet-950">
                    <div class="h-1.5 rounded-full {{ $percent >= 90 ? 'bg-red-500' : 'bg-violet-500' }}" style="width: {{ $percent }}%"></div>
                </div>
                <p class="mt-1 text-xs text-violet-500">{{ $percent }}% of quota</p>
            </div>
        @endforeach
    </div>

    <div class="mb-4">
        <select wire:model.live="bucketFilter" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
            <option value="">All buckets</option>
            @foreach ($buckets as $key => $config)
                <option value="{{ $key }}">{{ $config['label'] }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">File</th>
                    <th class="px-4 py-3">Bucket</th>
                    <th class="px-4 py-3">Attached to</th>
                    <th class="px-4 py-3">Uploaded by</th>
                    <th class="px-4 py-3">Size</th>
                    <th class="px-4 py-3">Status</th>
                    @if (auth('staff')->user()->hasPermission('storage.manage'))
                        <th class="px-4 py-3"></th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($assets as $asset)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.storage-assets.download', $asset) }}" class="text-violet-300 hover:text-violet-100">{{ $asset->original_filename }}</a>
                        </td>
                        <td class="px-4 py-3 text-violet-400">{{ $buckets[$asset->bucket]['label'] ?? $asset->bucket }}</td>
                        <td class="px-4 py-3 text-violet-400">{{ $asset->subjectLabel() }}</td>
                        <td class="px-4 py-3 text-violet-400">{{ $asset->uploadedByStaff?->display_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-violet-400">{{ number_format($asset->size_bytes / 1024, 0) }} KB</td>
                        <td class="px-4 py-3">
                            @if ($asset->isRetained())
                                <span class="rounded bg-amber-900/40 px-2 py-0.5 text-xs text-amber-300">Retained: {{ $asset->retained_reason }}</span>
                            @else
                                <span class="text-xs text-violet-500">—</span>
                            @endif
                        </td>
                        @if (auth('staff')->user()->hasPermission('storage.manage'))
                            <td class="px-4 py-3 text-right">
                                @if ($asset->isRetained())
                                    <button type="button" wire:click="unretain({{ $asset->id }})" class="text-xs text-violet-400 hover:text-violet-200">Unretain</button>
                                @elseif ($retainingAssetId === $asset->id)
                                    <div class="flex items-center justify-end gap-2">
                                        <input type="text" wire:model="retainReason" placeholder="Reason" class="w-32 rounded border border-violet-800/50 bg-black/30 px-2 py-1 text-xs text-white">
                                        <button type="button" wire:click="retain({{ $asset->id }})" class="text-xs text-emerald-400 hover:text-emerald-200">Save</button>
                                    </div>
                                @else
                                    <button type="button" wire:click="$set('retainingAssetId', {{ $asset->id }})" class="mr-3 text-xs text-violet-400 hover:text-violet-200">Retain</button>
                                @endif
                                <button type="button" wire:click="delete({{ $asset->id }})" wire:confirm="Delete this file permanently?" class="ml-3 text-xs text-red-400 hover:text-red-200">Delete</button>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-violet-400">No files uploaded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $assets->links() }}</div>
</div>
