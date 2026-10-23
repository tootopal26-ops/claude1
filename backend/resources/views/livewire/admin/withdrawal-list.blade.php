<div>
    <x-ui.page-header title="Withdrawals" description="Payout requests from players, from review through to settlement." />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-56">
            <label for="withdrawal-status" class="mb-1.5 block text-xs font-medium text-fg-2">Status</label>
            <select id="withdrawal-status" wire:model.live="statusFilter" class="mm-control">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="processing">Processing</option>
                <option value="paid">Paid</option>
                <option value="rejected">Rejected</option>
                <option value="failed">Failed</option>
            </select>
        </div>
        <span class="mm-spinner mb-2 text-fg-3" wire:loading wire:target="statusFilter" role="status"><span class="sr-only">Filtering…</span></span>
    </div>

    <x-ui.table wire:loading.class="opacity-60" wire:target="statusFilter">
        <x-slot:head>
            <tr>
                <th>Player</th>
                <th>Amount</th>
                <th>UPI ID</th>
                <th>Status</th>
                <th>Requested</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($withdrawals as $withdrawal)
            <tr wire:key="withdrawal-{{ $withdrawal->id }}">
                <td class="mm-cell-primary"><span class="block max-w-[14rem] truncate" title="{{ $withdrawal->user->username }}">{{ $withdrawal->user->username }}</span></td>
                <td class="whitespace-nowrap">
                    <span class="inline-flex items-center gap-1.5 tabular-nums text-fg"><x-coin-asset :size="16" />{{ $withdrawal->amount }}</span>
                </td>
                <td><span class="block max-w-[16rem] truncate" title="{{ $withdrawal->upi_id }}">{{ $withdrawal->upi_id }}</span></td>
                <td><x-ui.badge :status="$withdrawal->status" /></td>
                <td class="whitespace-nowrap">{{ $withdrawal->created_at->format('d M, H:i') }}</td>
                <td class="text-right">
                    <x-ui.button :href="portal_route('withdrawals.show', $withdrawal)" variant="ghost" size="sm" icon="chevron-right">Review</x-ui.button>
                </td>
            </tr>
        @empty
            @if ($statusFilter !== '')
                <x-ui.table-empty :colspan="6" icon="filter" title="No withdrawals with this status" description="Try another status, or clear the filter to see every request.">
                    <x-ui.button variant="outline" size="sm" wire:click="$set('statusFilter', '')">Clear filter</x-ui.button>
                </x-ui.table-empty>
            @else
                <x-ui.table-empty :colspan="6" icon="withdrawal" title="No withdrawal requests" description="Requests appear here when a player asks to cash out their winnings." />
            @endif
        @endforelse

        <x-slot:footer>{{ $withdrawals->links() }}</x-slot:footer>
    </x-ui.table>
</div>
