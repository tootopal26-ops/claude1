<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-violet-200">Deposits</h1>
        @if (auth('staff')->user()->hasPermission('payments.manage'))
            <a href="{{ route('admin.payment-settings') }}" class="text-sm text-violet-400 hover:text-violet-200">Payment settings &rarr;</a>
        @endif
    </div>

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="success">Success</option>
            <option value="failed">Failed</option>
            <option value="expired">Expired</option>
        </select>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Player</th>
                    <th class="px-4 py-3">Amount</th>
                    <th class="px-4 py-3">Order ID</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">UTR</th>
                    <th class="px-4 py-3">Created</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($deposits as $deposit)
                    <tr>
                        <td class="px-4 py-3 text-white">{{ $deposit->user->username }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $deposit->amount }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $deposit->our_order_id }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ ucfirst($deposit->status) }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $deposit->utr ?? '—' }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $deposit->created_at->format('d M, H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-violet-400">No deposits yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $deposits->links() }}</div>
</div>
