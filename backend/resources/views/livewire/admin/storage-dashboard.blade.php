@php
    $canManage = auth('staff')->user()->hasPermission('storage.manage');
    $columnCount = $canManage ? 5 : 4;
@endphp

<div>
    <x-ui.page-header title="Storage" description="How much space each bucket is using and which files are being kept." />

    @if ($actionError)
        <x-ui.alert variant="danger" title="That action couldn't be completed" class="mb-6">{{ $actionError }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($usage as $bucketUsage)
            @php
                $percent = $bucketUsage['quota_bytes'] > 0 ? min(100, (int) round($bucketUsage['total_bytes'] / $bucketUsage['quota_bytes'] * 100)) : 0;
                $retentionDays = $buckets[$bucketUsage['bucket']]['retention_days'] ?? null;
                $barTone = $percent >= 90 ? 'bg-danger' : ($percent >= 75 ? 'bg-warning' : 'bg-brand');
            @endphp
            <x-ui.card wire:key="usage-{{ $bucketUsage['bucket'] }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-fg-2">{{ $bucketUsage['label'] }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-fg">{{ number_format($bucketUsage['total_bytes'] / 1048576, 1) }} <span class="text-sm font-medium text-fg-3">MB</span></p>
                    </div>
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-surface-3 text-fg-2"><x-icon name="storage" :size="16" /></span>
                </div>

                <div class="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-surface-3" role="progressbar" aria-label="{{ $bucketUsage['label'] }} quota used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}">
                    <div class="h-full rounded-full {{ $barTone }}" style="width: {{ $percent }}%"></div>
                </div>
                <div class="mt-2 flex items-center justify-between gap-3 text-xs text-fg-3">
                    <span class="tabular-nums">{{ $percent }}% of {{ number_format($bucketUsage['quota_bytes'] / 1048576, 0) }} MB quota</span>
                    <span class="tabular-nums">{{ $bucketUsage['count'] }} {{ $bucketUsage['count'] === 1 ? 'file' : 'files' }}</span>
                </div>
                <p class="mt-3 border-t border-line pt-3 text-xs text-fg-3">
                    <x-icon name="clock" :size="12" class="mr-1 inline align-[-1px]" />
                    @if ($retentionDays)
                        Cleaned up automatically after {{ $retentionDays }} days
                    @else
                        Kept indefinitely
                    @endif
                </p>
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.alert variant="info" title="How retention works" class="mt-6">
        Files are removed automatically by the weekly cleanup once they pass their bucket's retention period. Mark a file
        <strong class="font-semibold text-fg">Retained</strong> to exempt it — for example evidence tied to an open dispute or audit. Quotas are advisory: uploads are never blocked by them.
    </x-ui.alert>

    <div class="mb-4 mt-8 flex flex-wrap items-end justify-between gap-3">
        <h2 class="text-base font-semibold text-fg">Files</h2>
        <div class="w-full sm:w-64">
            <x-ui.select label="Bucket" wire:model.live="bucketFilter">
                <option value="">All buckets</option>
                @foreach ($buckets as $key => $config)
                    <option value="{{ $key }}">{{ $config['label'] }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>File</th>
                <th>Bucket</th>
                <th class="text-right">Size</th>
                <th>Status</th>
                @if ($canManage)
                    <th><span class="sr-only">Actions</span></th>
                @endif
            </tr>
        </x-slot:head>

        @forelse ($assets as $asset)
            <tr wire:key="asset-{{ $asset->id }}">
                <td class="mm-cell-primary">
                    <a href="{{ portal_route('storage-assets.download', $asset) }}" class="inline-flex max-w-[15rem] items-center gap-2 hover:text-brand-text" title="Download {{ $asset->original_filename }}">
                        <x-icon name="download" :size="14" class="shrink-0 text-fg-3" />
                        <span class="truncate">{{ $asset->original_filename }}</span>
                    </a>
                    <span class="block max-w-[15rem] truncate text-xs font-normal text-fg-3" title="Uploaded by {{ $asset->uploadedByStaff?->display_name ?? 'unknown' }}">Uploaded by {{ $asset->uploadedByStaff?->display_name ?? '—' }}</span>
                </td>
                <td>
                    <span class="block whitespace-nowrap">{{ $buckets[$asset->bucket]['label'] ?? $asset->bucket }}</span>
                    <span class="block whitespace-nowrap text-xs text-fg-3">Attached to {{ $asset->subjectLabel() }}</span>
                </td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ number_format($asset->size_bytes / 1024, 0) }} KB</td>
                <td>
                    @if ($asset->isRetained())
                        <x-ui.badge variant="warning" :dot="false" title="{{ $asset->retained_reason }}">Retained</x-ui.badge>
                        <span class="mt-1 block max-w-[11rem] truncate text-xs text-fg-3" title="{{ $asset->retained_reason }}">{{ $asset->retained_reason }}</span>
                    @else
                        <span class="text-fg-3">—</span>
                    @endif
                </td>
                @if ($canManage)
                    <td class="text-right">
                        <div class="ml-auto flex max-w-[19rem] flex-wrap items-center justify-end gap-1.5">
                            @if ($asset->isRetained())
                                <x-ui.button wire:click="unretain({{ $asset->id }})" variant="ghost" size="sm">Unretain</x-ui.button>
                            @elseif ($retainingAssetId === $asset->id)
                                <input type="text" wire:model="retainReason" placeholder="Reason to retain" aria-label="Reason to retain {{ $asset->original_filename }}" class="mm-control h-8 w-full basis-full text-xs">
                                <x-ui.button wire:click="retain({{ $asset->id }})" size="sm" icon="check">Save</x-ui.button>
                                <x-ui.button wire:click="$set('retainingAssetId', null)" variant="ghost" size="sm">Cancel</x-ui.button>
                            @else
                                <x-ui.button wire:click="$set('retainingAssetId', {{ $asset->id }})" variant="ghost" size="sm">Retain</x-ui.button>
                            @endif
                            <x-ui.button wire:click="delete({{ $asset->id }})" variant="danger-ghost" size="sm" icon="trash" icon-only title="Delete {{ $asset->original_filename }}" aria-label="Delete {{ $asset->original_filename }}" :confirm="'Delete '.$asset->original_filename.' permanently? This can\'t be undone.'" confirm-title="Delete file" confirm-label="Delete file" />
                        </div>
                    </td>
                @endif
            </tr>
        @empty
            <x-ui.table-empty :colspan="$columnCount" icon="storage" title="No files uploaded yet" description="Tournament evidence, withdrawal proof and internal documents will be listed here once they're uploaded." />
        @endforelse

        <x-slot:footer>{{ $assets->links() }}</x-slot:footer>
    </x-ui.table>
</div>
