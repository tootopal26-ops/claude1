<div>
    <x-ui.flash />

    <x-ui.page-header title="App Releases" description="Android builds players download directly — this project is distributed privately, with no Play Store listing. Only the release marked Current is ever served by the public download link.">
        <x-slot:actions>
            <x-ui.button :href="portal_route('app-releases.create')" icon="plus">Upload release</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.card>
            <p class="text-xs font-medium text-fg-3">Total downloads</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-fg">{{ number_format($downloadsTotal) }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium text-fg-3">Last 7 days</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-fg">{{ number_format($downloads7d) }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium text-fg-3">Last 30 days</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-fg">{{ number_format($downloads30d) }}</p>
        </x-ui.card>
    </div>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Version</th>
                <th>Uploaded</th>
                <th>Size</th>
                <th>Downloads</th>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($releases as $release)
            <tr wire:key="release-{{ $release->id }}">
                <td class="mm-cell-primary">
                    <span class="block">{{ $release->version_name }}</span>
                    <span class="block text-xs font-normal text-fg-3">Build {{ $release->version_code }} · {{ strtoupper($release->fileAsset?->original_filename ? pathinfo($release->fileAsset->original_filename, PATHINFO_EXTENSION) : 'APK') }}</span>
                </td>
                <td class="whitespace-nowrap text-fg-2">
                    {{ $release->created_at->format('d M Y, H:i') }}
                    <span class="block text-xs text-fg-3">{{ $release->uploadedByStaff?->display_name ?? 'Unknown' }}</span>
                </td>
                <td class="tabular-nums text-fg-2">{{ $release->fileAsset ? number_format($release->fileAsset->size_bytes / 1048576, 1).' MB' : '—' }}</td>
                <td class="tabular-nums text-fg-2">{{ number_format($release->download_count) }}</td>
                <td>
                    @if ($release->is_current)
                        <x-ui.badge variant="success">Current</x-ui.badge>
                    @else
                        <x-ui.badge variant="neutral">Archived</x-ui.badge>
                    @endif
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-1">
                        @if ($release->fileAsset)
                            <x-ui.button :href="portal_route('storage-assets.download', $release->fileAsset)" variant="ghost" size="sm" icon="download">Download</x-ui.button>
                        @endif
                        @unless ($release->is_current)
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="setCurrent({{ $release->id }})" loading="setCurrent" confirm="Make build {{ $release->version_code }} the one players download?" confirm-title="Set current release" confirm-label="Set current">Set current</x-ui.button>
                            <x-ui.button type="button" variant="danger-ghost" size="sm" icon="trash" wire:click="delete({{ $release->id }})" loading="delete" confirm="Delete this release permanently? Players can no longer download it." confirm-title="Delete release" confirm-label="Delete">Delete</x-ui.button>
                        @endunless
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="6" icon="download" title="No releases uploaded yet" description="Upload an APK or AAB and mark it current to let players download it.">
                <x-ui.button :href="portal_route('app-releases.create')" icon="plus" size="sm">Upload release</x-ui.button>
            </x-ui.table-empty>
        @endforelse

        <x-slot:footer>{{ $releases->links() }}</x-slot:footer>
    </x-ui.table>
</div>
