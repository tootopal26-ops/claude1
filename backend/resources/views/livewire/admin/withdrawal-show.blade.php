@php
    $status = $withdrawal->status;
    $steps = ['pending' => 'Requested', 'approved' => 'Approved', 'processing' => 'Processing', 'paid' => 'Paid'];
    $stepKeys = array_keys($steps);
    $currentStep = array_search($status, $stepKeys, true);
    $isTerminalFailure = in_array($status, ['rejected', 'failed'], true);
@endphp

<div>
    <x-ui.flash />

    <x-ui.page-header
        title="Withdrawal request"
        :description="'Requested by '.$withdrawal->user->username.' on '.$withdrawal->created_at->format('d M Y, H:i').'.'"
        :breadcrumbs="[
            ['label' => 'Withdrawals', 'url' => route('admin.withdrawals.index')],
            ['label' => '#'.$withdrawal->id],
        ]"
    >
        <x-slot:actions><x-ui.badge :status="$status" class="px-3 py-1 text-sm" /></x-slot:actions>
    </x-ui.page-header>

    @if ($actionError)
        <x-ui.alert variant="danger" title="That action couldn't be completed" class="mb-6">{{ $actionError }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-ui.card title="Request details">
                <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                    <x-ui.kv label="Player">
                        @if (auth('staff')->user()->hasPermission('users.view'))
                            <a href="{{ route('admin.users.show', $withdrawal->user) }}" class="font-medium text-brand-text hover:underline">{{ $withdrawal->user->username }}</a>
                        @else
                            {{ $withdrawal->user->username }}
                        @endif
                    </x-ui.kv>
                    <x-ui.kv label="Amount"><span class="inline-flex items-center gap-1.5 text-lg font-semibold tabular-nums"><x-coin-asset :size="18" />{{ $withdrawal->amount }}</span></x-ui.kv>
                    <x-ui.kv label="UPI ID"><span class="break-all">{{ $withdrawal->upi_id }}</span></x-ui.kv>
                    <x-ui.kv label="Requested">{{ $withdrawal->created_at->format('d M Y, H:i') }}</x-ui.kv>
                    @if ($withdrawal->reviewed_at)
                        <x-ui.kv label="Reviewed">
                            {{ $withdrawal->reviewed_at->format('d M Y, H:i') }}
                            @if ($withdrawal->reviewedByStaff)<span class="block text-xs text-fg-3">by {{ $withdrawal->reviewedByStaff->display_name }}</span>@endif
                        </x-ui.kv>
                    @endif
                    @if ($withdrawal->settled_at)
                        <x-ui.kv label="Settled">
                            {{ $withdrawal->settled_at->format('d M Y, H:i') }}
                            @if ($withdrawal->settledByStaff)<span class="block text-xs text-fg-3">by {{ $withdrawal->settledByStaff->display_name }}</span>@endif
                        </x-ui.kv>
                    @endif
                </dl>

                @if ($withdrawal->rejection_reason)
                    <x-ui.alert variant="danger" title="Rejected" class="mt-6">{{ $withdrawal->rejection_reason }}</x-ui.alert>
                @endif
                @if ($withdrawal->failure_reason)
                    <x-ui.alert variant="danger" title="Payout failed" class="mt-6">{{ $withdrawal->failure_reason }}</x-ui.alert>
                @endif
                @if ($withdrawal->settlement_reference)
                    <x-ui.alert variant="success" title="Settled" class="mt-6">Reference <span class="font-mono text-fg">{{ $withdrawal->settlement_reference }}</span></x-ui.alert>
                @endif
            </x-ui.card>

            <x-ui.card title="Progress" description="A withdrawal is only marked paid after a settlement reference is recorded.">
                @if ($isTerminalFailure)
                    <p class="flex items-center gap-2 text-sm text-fg-2"><x-icon name="x-circle" :size="16" class="shrink-0 text-danger" /><span>This request ended as <strong class="font-semibold text-fg">{{ $status }}</strong>; the reserved amount was returned to the player's winnings.</span></p>
                @else
                    <ol class="grid grid-cols-4 gap-2" aria-label="Withdrawal progress">
                        @foreach ($steps as $key => $label)
                            @php($index = array_search($key, $stepKeys, true))
                            <li class="min-w-0">
                                <div @class(['h-1 rounded-full', 'bg-brand' => $index <= $currentStep, 'bg-surface-3' => $index > $currentStep])></div>
                                <p @class(['mt-2 truncate text-xs font-medium', 'text-fg' => $index <= $currentStep, 'text-fg-3' => $index > $currentStep])>
                                    {{ $label }}
                                    @if ($index === $currentStep)<span class="sr-only">(current)</span>@endif
                                </p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-6">
            @if ($status === 'pending')
                <x-ui.card title="Review" description="Approve to move this request into payout.">
                    <x-ui.button wire:click="approve" class="w-full" icon="check" loading="approve" confirm="Approve this withdrawal request?" confirm-title="Approve withdrawal" confirm-label="Approve">Approve</x-ui.button>
                </x-ui.card>
            @endif

            @if ($status === 'approved')
                <x-ui.card title="Payout" description="Mark as processing once you've started the bank transfer.">
                    <x-ui.button wire:click="markProcessing" class="w-full" loading="markProcessing">Mark as processing</x-ui.button>
                </x-ui.card>
            @endif

            @if ($status === 'processing')
                <x-ui.card title="Mark as paid" description="Record the UTR / transaction ID from the bank transfer.">
                    <form wire:submit="markPaid" class="space-y-4" novalidate>
                        <x-ui.input label="Settlement reference" wire:model="settlementReference" required placeholder="UTR / transaction ID" />
                        <x-ui.button type="submit" class="w-full" icon="check" loading="markPaid">Mark as paid</x-ui.button>
                    </form>
                </x-ui.card>

                <x-ui.card title="Mark as failed" description="The amount is returned to the player's winnings.">
                    <form wire:submit="markFailed" class="space-y-4" novalidate>
                        <x-ui.input label="Reason" wire:model="failureReason" required hint="Shown to the player." />
                        <x-ui.button type="submit" variant="danger" class="w-full" loading="markFailed" confirm="Mark this payout as failed and return the amount to the player?" confirm-title="Mark payout as failed" confirm-label="Mark as failed">Mark as failed</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if (in_array($status, ['pending', 'approved']))
                <x-ui.card title="Reject" description="The reserved amount is returned to the player.">
                    <form wire:submit="reject" class="space-y-4" novalidate>
                        <x-ui.input label="Reason" wire:model="rejectionReason" required hint="Shown to the player." />
                        <x-ui.button type="submit" variant="danger" class="w-full" loading="reject" confirm="Reject this withdrawal and return the amount to the player?" confirm-title="Reject withdrawal" confirm-label="Reject">Reject</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if (! in_array($status, ['pending', 'approved', 'processing']))
                <x-ui.card title="No actions available">
                    <p class="text-sm text-fg-2">This request is {{ $status }} and can't be changed. Every step is recorded in the audit log.</p>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
