<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-violet-200">Staff salary settlements</h1>
        @if (auth('staff')->user()->hasPermission('salary.manage'))
            <a href="{{ route('admin.staff-salary.rules.index') }}" class="text-sm text-violet-400 hover:text-violet-200">Manage rules &rarr;</a>
        @endif
    </div>

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="paid">Paid</option>
        </select>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Staff member</th>
                    <th class="px-4 py-3">Week</th>
                    <th class="px-4 py-3">Total</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($settlements as $settlement)
                    <tr>
                        <td class="px-4 py-3 text-white">{{ $settlement->staffUser->display_name }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $settlement->period_start->format('d M Y') }} &ndash; {{ $settlement->period_end->copy()->subDay()->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-emerald-400">{{ $settlement->total_amount }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ ucfirst($settlement->status) }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.staff-salary.settlements.show', $settlement) }}" class="text-violet-400 hover:text-violet-200">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-violet-400">No settlements yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $settlements->links() }}</div>
</div>
