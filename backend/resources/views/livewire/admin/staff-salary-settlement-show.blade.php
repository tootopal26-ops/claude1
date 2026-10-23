@php
    $periodLabel = $settlement->period_start->format('d M Y').' – '.$settlement->period_end->copy()->subDay()->format('d M Y');
@endphp

<div>
    <x-ui.flash />

    <x-ui.page-header
        title="Salary settlement"
        :description="$settlement->staffUser->display_name.' · week of '.$periodLabel"
        :breadcrumbs="[
            ['label' => 'Salary settlements', 'url' => portal_route('staff-salary.settlements.index')],
            ['label' => $settlement->staffUser->display_name.' · '.$settlement->period_start->format('d M')],
        ]"
    >
        <x-slot:actions><x-ui.badge :status="$settlement->status" class="px-3 py-1 text-sm" /></x-slot:actions>
    </x-ui.page-header>

    @if ($actionError)
        <x-ui.alert variant="danger" title="That action couldn't be completed" class="mb-6">{{ $actionError }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="min-w-0 space-y-6 xl:col-span-2">
            <x-ui.card title="Settlement details">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.kv label="Staff member">{{ $settlement->staffUser->display_name }}</x-ui.kv>
                    <x-ui.kv label="Week">{{ $periodLabel }}</x-ui.kv>
                    <x-ui.kv label="Total"><span class="inline-flex items-center gap-1.5 text-lg font-semibold tabular-nums"><x-coin-asset :size="18" />{{ $settlement->total_amount }}</span></x-ui.kv>
                </dl>

                @if ($settlement->isPaid())
                    <x-ui.alert variant="success" title="Paid" class="mt-6">
                        Reference <span class="font-mono text-fg">{{ $settlement->payment_reference }}</span>@if ($settlement->paidByStaff) by {{ $settlement->paidByStaff->display_name }}@endif @if ($settlement->paid_at) on {{ $settlement->paid_at->format('d M Y, H:i') }}@endif.
                    </x-ui.alert>
                @endif
            </x-ui.card>

            <x-ui.card title="Line items" description="Each payable action that makes up this settlement." :padding="false">
                <x-ui.table class="rounded-none border-0">
                    <x-slot:head>
                        <tr>
                            <th>Action</th>
                            <th>Occurred</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </x-slot:head>

                    @forelse ($items as $item)
                        <tr wire:key="item-{{ $item->id }}">
                            <td><span class="block max-w-[20rem] truncate font-mono text-xs text-fg" title="{{ $item->event->action_type }}">{{ $item->event->action_type }}</span></td>
                            <td class="whitespace-nowrap">{{ $item->event->occurred_at->format('d M, H:i') }}</td>
                            <td class="whitespace-nowrap text-right"><span class="inline-flex items-center gap-1.5 tabular-nums text-fg"><x-coin-asset :size="16" />{{ $item->amount }}</span></td>
                        </tr>
                    @empty
                        <x-ui.table-empty :colspan="3" icon="audit" title="No line items" description="This settlement has no payable actions recorded." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="min-w-0 space-y-6">
            @if (! $settlement->isPaid() && auth('staff')->user()->hasPermission('salary.approve'))
                <x-ui.card title="Mark as paid" description="Record the payment reference once the staff member has been paid. This can't be undone.">
                    <form wire:submit="markPaid" class="space-y-4" novalidate>
                        <x-ui.input label="Payment reference" wire:model="paymentReference" required placeholder="e.g. UTR / transaction ID" />
                        <x-ui.button type="submit" class="w-full" icon="check" loading="markPaid" confirm="Mark this settlement as paid?" confirm-title="Mark settlement as paid" confirm-label="Mark as paid">Mark as paid</x-ui.button>
                    </form>
                </x-ui.card>
            @elseif (! $settlement->isPaid())
                <x-ui.card title="Awaiting payment">
                    <p class="text-sm text-fg-2">This settlement is pending. Someone with salary approval access records the payment.</p>
                </x-ui.card>
            @else
                <x-ui.card title="No actions available">
                    <p class="text-sm text-fg-2">This settlement is paid and can't be changed. Every step is recorded in the audit log.</p>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
