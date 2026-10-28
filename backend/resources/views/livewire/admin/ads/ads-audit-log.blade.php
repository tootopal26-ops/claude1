<div>
    <x-ui.page-header title="Ads audit log" description="Who changed ad settings, ad units, placements, campaigns, revenue figures and settlements — with previous and new values. Entries can't be edited or deleted." />

    @include('livewire.admin.ads._nav')

    <x-ui.card class="mb-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.input label="From" type="date" wire:model.live="dateFrom" />
            <x-ui.input label="To" type="date" wire:model.live="dateTo" />
        </div>
    </x-ui.card>

    <x-ui.table label="Ads audit log">
        <x-slot:head><tr><th>When</th><th>Actor</th><th>Action</th><th>Subject</th><th>Details</th></tr></x-slot:head>
        @forelse ($logs as $log)
            <tr wire:key="aal-{{ $log->id }}">
                <td class="whitespace-nowrap"><time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->format('d M Y, H:i') }}</time></td>
                <td class="mm-cell-primary">{{ $log->actorLabel() }}</td>
                <td><code class="rounded bg-surface-2 px-1.5 py-0.5 text-xs text-fg">{{ $log->action }}</code></td>
                <td class="whitespace-nowrap">{{ $log->action === 'platform_settings.updated' ? (\App\Domain\Settings\PlatformSettingsService::CATALOG[$log->metadata['key'] ?? '']['label'] ?? $log->metadata['key'] ?? '') : $log->subjectLabel() }}</td>
                <td><span class="block max-w-[28rem] truncate font-mono text-xs text-fg-3" title="{{ json_encode($log->metadata) }}">{{ \Illuminate\Support\Str::limit(json_encode($log->metadata), 140) }}</span></td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="5" icon="audit" title="No ad-related changes recorded yet" />
        @endforelse
        <x-slot:footer>{{ $logs->links() }}</x-slot:footer>
    </x-ui.table>
</div>
