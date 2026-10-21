<div>
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Audit log</h1>

    <div class="mb-4 flex flex-wrap items-end gap-4 rounded-2xl border border-violet-900/40 bg-white/5 p-4">
        <div>
            <label class="mb-1 block text-xs text-violet-300">Action</label>
            <select wire:model.live="action" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
                <option value="">All actions</option>
                @foreach ($actions as $actionOption)
                    <option value="{{ $actionOption }}">{{ $actionOption }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs text-violet-300">From</label>
            <input type="date" wire:model.live="dateFrom" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
        </div>
        <div>
            <label class="mb-1 block text-xs text-violet-300">To</label>
            <input type="date" wire:model.live="dateTo" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Actor</th>
                    <th class="px-4 py-3">Action</th>
                    <th class="px-4 py-3">Subject</th>
                    <th class="px-4 py-3">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-3 text-violet-300">{{ $log->created_at->format('d M, H:i:s') }}</td>
                        <td class="px-4 py-3 text-white">{{ $log->actorLabel() }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $log->action }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $log->subjectLabel() }}</td>
                        <td class="px-4 py-3 text-violet-500">{{ $log->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-violet-400">No audit events match these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</div>
