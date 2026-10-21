<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-violet-200">Notifications</h1>
        <a href="{{ route('admin.notifications.create') }}"
            class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
            + Send notification
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Title</th>
                    <th class="px-4 py-3">Audience</th>
                    <th class="px-4 py-3">Recipients</th>
                    <th class="px-4 py-3">Delivered</th>
                    <th class="px-4 py-3">Failed</th>
                    <th class="px-4 py-3">Sent by</th>
                    <th class="px-4 py-3">When</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($batches as $batch)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium text-white">{{ $batch->title }}</p>
                            <p class="text-xs text-violet-400">{{ Str::limit($batch->body, 60) }}</p>
                        </td>
                        <td class="px-4 py-3 text-violet-300">
                            {{ match($batch->audience_type) {
                                'all' => 'All active users',
                                'selected_users' => 'Selected users',
                                'tournament_participants' => 'Tournament participants',
                                default => $batch->audience_type,
                            } }}
                        </td>
                        <td class="px-4 py-3 text-violet-300">{{ $batch->recipient_count }}</td>
                        <td class="px-4 py-3 text-emerald-400">{{ $batch->push_sent_count }}</td>
                        <td class="px-4 py-3 {{ $batch->push_failed_count > 0 ? 'text-red-400' : 'text-violet-500' }}">{{ $batch->push_failed_count }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $batch->createdByStaff->display_name ?? 'System' }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $batch->sent_at?->format('d M, H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-violet-400">No notifications sent yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $batches->links() }}</div>
</div>
