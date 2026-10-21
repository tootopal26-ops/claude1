<div>
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Withdrawals</h1>

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="processing">Processing</option>
            <option value="paid">Paid</option>
            <option value="rejected">Rejected</option>
            <option value="failed">Failed</option>
        </select>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Player</th>
                    <th class="px-4 py-3">Amount</th>
                    <th class="px-4 py-3">UPI ID</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Requested</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($withdrawals as $withdrawal)
                    <tr>
                        <td class="px-4 py-3 text-white">{{ $withdrawal->user->username }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $withdrawal->amount }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $withdrawal->upi_id }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ ucfirst($withdrawal->status) }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $withdrawal->created_at->format('d M, H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.withdrawals.show', $withdrawal) }}" class="text-violet-400 hover:text-violet-200">Review</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-violet-400">No withdrawal requests.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $withdrawals->links() }}</div>
</div>
