<div>
    <x-ui.page-header title="Deposits" description="Payment orders players have started, with the gateway's verdict on each.">
        <x-slot:actions>
            @if (auth('staff')->user()->hasPermission('payments.manage'))
                <x-ui.button :href="route('admin.payment-settings')" variant="outline" icon="settings">Payment settings</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-56">
            <label for="deposit-status" class="mb-1.5 block text-xs font-medium text-fg-2">Status</label>
            <select id="deposit-status" wire:model.live="statusFilter" class="mm-control">
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="success">Success</option>
                <option value="failed">Failed</option>
                <option value="expired">Expired</option>
            </select>
        </div>
        <span class="mm-spinner mb-2 text-fg-3" wire:loading wire:target="statusFilter" role="status"><span class="sr-only">Filtering…</span></span>
    </div>

    <x-ui.table wire:loading.class="opacity-60" wire:target="statusFilter">
        <x-slot:head>
            <tr>
                <th>Player</th>
                <th>Amount</th>
                <th>Order ID</th>
                <th>Status</th>
                <th>UTR</th>
                <th>Created</th>
            </tr>
        </x-slot:head>

        @forelse ($deposits as $deposit)
            <tr wire:key="deposit-{{ $deposit->id }}">
                <td class="mm-cell-primary"><span class="block max-w-[14rem] truncate" title="{{ $deposit->user->username }}">{{ $deposit->user->username }}</span></td>
                <td class="whitespace-nowrap">
                    <span class="inline-flex items-center gap-1.5 tabular-nums text-fg"><x-coin-asset :size="16" />{{ $deposit->amount }}</span>
                </td>
                <td><span class="block max-w-[14rem] truncate font-mono text-xs" title="{{ $deposit->our_order_id }}">{{ $deposit->our_order_id }}</span></td>
                <td><x-ui.badge :status="$deposit->status" /></td>
                <td>
                    @if ($deposit->utr)
                        <span class="block max-w-[12rem] truncate font-mono text-xs" title="{{ $deposit->utr }}">{{ $deposit->utr }}</span>
                    @else
                        <span class="text-fg-3">—</span>
                    @endif
                </td>
                <td class="whitespace-nowrap">{{ $deposit->created_at->format('d M, H:i') }}</td>
            </tr>
        @empty
            @if ($statusFilter !== '')
                <x-ui.table-empty :colspan="6" icon="filter" title="No deposits with this status" description="Try another status, or clear the filter to see every deposit.">
                    <x-ui.button variant="outline" size="sm" wire:click="$set('statusFilter', '')">Clear filter</x-ui.button>
                </x-ui.table-empty>
            @else
                <x-ui.table-empty :colspan="6" icon="deposit" title="No deposits yet" description="Deposits appear here as soon as a player starts a payment." />
            @endif
        @endforelse

        <x-slot:footer>{{ $deposits->links() }}</x-slot:footer>
    </x-ui.table>
</div>
