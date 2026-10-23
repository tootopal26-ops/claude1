<div>
    <x-ui.page-header title="Audit log" description="A read-only record of administrative and player actions. Entries can't be edited or deleted." />

    <x-ui.card class="mb-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-ui.select label="Action" wire:model.live="action">
                <option value="">All actions</option>
                @foreach ($actions as $actionOption)
                    <option value="{{ $actionOption }}">{{ $actionOption }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="From" type="date" wire:model.live="dateFrom" />
            <x-ui.input label="To" type="date" wire:model.live="dateTo" />
        </div>
    </x-ui.card>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>When</th>
                <th>Actor</th>
                <th>Action</th>
                <th>Subject</th>
                <th>IP</th>
            </tr>
        </x-slot:head>

        @forelse ($logs as $log)
            <tr wire:key="log-{{ $log->id }}">
                <td class="whitespace-nowrap">
                    <time datetime="{{ $log->created_at->toIso8601String() }}" title="{{ $log->created_at->format('d M Y, H:i:s') }}">{{ $log->created_at->format('d M, H:i:s') }}</time>
                </td>
                <td class="mm-cell-primary"><span class="block max-w-[14rem] truncate" title="{{ $log->actorLabel() }}">{{ $log->actorLabel() }}</span></td>
                <td>
                    <code class="inline-block max-w-[18rem] truncate rounded bg-surface-2 px-1.5 py-0.5 align-middle text-xs text-fg" title="{{ $log->action }}">{{ $log->action }}</code>
                </td>
                <td class="whitespace-nowrap">{{ $log->subjectLabel() }}</td>
                <td class="whitespace-nowrap font-mono text-xs text-fg-3">{{ $log->ip_address ?? '—' }}</td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="5" icon="audit" title="No audit events match these filters" description="Try a different action or widen the date range." />
        @endforelse

        <x-slot:footer>{{ $logs->links() }}</x-slot:footer>
    </x-ui.table>
</div>
