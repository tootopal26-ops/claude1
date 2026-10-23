<div>
    <x-ui.flash />

    <x-ui.page-header
        title="Salary settlements"
        description="Weekly payouts generated from each staff member's salary rules. Open one to review its line items and record payment."
        :breadcrumbs="[
            ['label' => 'Team Management'],
            ['label' => 'Salary settlements'],
        ]"
    >
        <x-slot:actions>
            @if (auth('staff')->user()->hasPermission('salary.manage'))
                <x-ui.button :href="portal_route('staff-salary.rules.index')" variant="secondary" icon="presets">Manage rules</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <x-ui.select label="Status" wire:model.live="statusFilter" wrapperClass="w-full sm:w-48">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="paid">Paid</option>
        </x-ui.select>
    </div>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Staff member</th>
                <th>Week</th>
                <th>Total</th>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($settlements as $settlement)
            <tr wire:key="settlement-{{ $settlement->id }}">
                <td class="mm-cell-primary"><span class="block max-w-[16rem] truncate" title="{{ $settlement->staffUser->display_name }}">{{ $settlement->staffUser->display_name }}</span></td>
                <td class="whitespace-nowrap">{{ $settlement->period_start->format('d M Y') }} &ndash; {{ $settlement->period_end->copy()->subDay()->format('d M Y') }}</td>
                <td class="whitespace-nowrap"><span class="inline-flex items-center gap-1.5 tabular-nums text-fg"><x-coin-asset :size="16" />{{ $settlement->total_amount }}</span></td>
                <td><x-ui.badge :status="$settlement->status" /></td>
                <td class="text-right">
                    <x-ui.button :href="portal_route('staff-salary.settlements.show', $settlement)" variant="ghost" size="sm" icon="chevron-right">View</x-ui.button>
                </td>
            </tr>
        @empty
            <x-ui.table-empty
                :colspan="5"
                icon="salary"
                :title="$statusFilter !== '' ? 'No settlements match this filter' : 'No settlements yet'"
                :description="$statusFilter !== '' ? 'Try a different status, or clear the filter to see every settlement.' : 'Settlements are generated weekly once staff perform actions covered by a salary rule.'"
            />
        @endforelse

        <x-slot:footer>{{ $settlements->links() }}</x-slot:footer>
    </x-ui.table>
</div>
