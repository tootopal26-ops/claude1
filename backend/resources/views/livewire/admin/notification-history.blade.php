<div>
    <x-ui.flash />

    <x-ui.page-header title="Notifications" description="Every broadcast sent to players, with how many received it.">
        <x-slot:actions>
            <x-ui.button :href="portal_route('notifications.create')" icon="plus">Send notification</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Notification</th>
                <th>Audience</th>
                <th class="text-right">Recipients</th>
                <th class="text-right">Delivered</th>
                <th class="text-right">Failed</th>
                <th>Sent by</th>
                <th>When</th>
            </tr>
        </x-slot:head>

        @forelse ($batches as $batch)
            <tr wire:key="batch-{{ $batch->id }}">
                <td class="mm-cell-primary">
                    <span class="block max-w-[20rem] truncate" title="{{ $batch->title }}">{{ $batch->title }}</span>
                    <span class="block max-w-[20rem] truncate text-xs font-normal text-fg-3" title="{{ $batch->body }}">{{ Str::limit($batch->body, 60) }}</span>
                </td>
                <td class="whitespace-nowrap">
                    {{ match($batch->audience_type) {
                        'all' => 'All active users',
                        'selected_users' => 'Selected users',
                        'tournament_participants' => 'Tournament participants',
                        default => $batch->audience_type,
                    } }}
                </td>
                <td class="text-right tabular-nums text-fg">{{ number_format($batch->recipient_count) }}</td>
                <td class="text-right tabular-nums text-success">{{ number_format($batch->push_sent_count) }}</td>
                <td class="text-right tabular-nums">
                    @if ($batch->push_failed_count > 0)
                        <x-ui.badge variant="danger" :dot="false">{{ number_format($batch->push_failed_count) }}</x-ui.badge>
                    @else
                        <span class="text-fg-3">0</span>
                    @endif
                </td>
                <td class="whitespace-nowrap">{{ $batch->createdByStaff->display_name ?? 'System' }}</td>
                <td class="whitespace-nowrap">
                    @if ($batch->sent_at)
                        <time datetime="{{ $batch->sent_at->toIso8601String() }}" title="{{ $batch->sent_at->format('d M Y, H:i:s') }}">{{ $batch->sent_at->format('d M, H:i') }}</time>
                    @else
                        <span class="text-fg-3">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="7" icon="notifications" title="No notifications sent yet" description="Broadcasts you send to players will be listed here with their delivery results.">
                <x-ui.button :href="portal_route('notifications.create')" icon="plus" size="sm">Send notification</x-ui.button>
            </x-ui.table-empty>
        @endforelse

        <x-slot:footer>{{ $batches->links() }}</x-slot:footer>
    </x-ui.table>
</div>
