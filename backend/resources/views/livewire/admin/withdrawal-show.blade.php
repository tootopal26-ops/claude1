<div class="mx-auto max-w-2xl space-y-6">
    <a href="{{ route('admin.withdrawals.index') }}" class="text-sm text-violet-400 hover:text-violet-200">&larr; Back to withdrawals</a>

    @if (session('status'))
        <div class="rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    @if ($actionError)
        <div class="rounded-lg bg-red-900/40 px-4 py-3 text-sm text-red-300">{{ $actionError }}</div>
    @endif

    <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <h1 class="text-2xl font-bold text-white">{{ $withdrawal->user->username }}</h1>
        <p class="mt-1 text-sm text-violet-400">Requested {{ $withdrawal->created_at->format('d M Y, H:i') }}</p>

        <div class="mt-4 grid grid-cols-3 gap-4">
            <div>
                <p class="text-xs text-violet-400">Amount</p>
                <p class="text-xl font-bold text-white">{{ $withdrawal->amount }} coins</p>
            </div>
            <div>
                <p class="text-xs text-violet-400">UPI ID</p>
                <p class="text-xl font-bold text-white">{{ $withdrawal->upi_id }}</p>
            </div>
            <div>
                <p class="text-xs text-violet-400">Status</p>
                <p class="text-xl font-bold text-violet-300">{{ ucfirst($withdrawal->status) }}</p>
            </div>
        </div>

        @if ($withdrawal->rejection_reason)
            <p class="mt-4 text-sm text-red-400">Rejected: {{ $withdrawal->rejection_reason }}</p>
        @endif
        @if ($withdrawal->failure_reason)
            <p class="mt-4 text-sm text-red-400">Failed: {{ $withdrawal->failure_reason }}</p>
        @endif
        @if ($withdrawal->settlement_reference)
            <p class="mt-4 text-sm text-emerald-400">
                Settled with reference "{{ $withdrawal->settlement_reference }}" by {{ $withdrawal->settledByStaff?->display_name }}
                on {{ $withdrawal->settled_at?->format('d M Y, H:i') }}.
            </p>
        @endif
    </div>

    @if ($withdrawal->status === 'pending')
        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
            <button type="button" wire:click="approve" wire:confirm="Approve this withdrawal?"
                class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
                Approve
            </button>
        </div>
    @endif

    @if (in_array($withdrawal->status, ['pending', 'approved']))
        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
            <h2 class="mb-4 text-lg font-bold text-violet-200">Reject</h2>
            <form wire:submit="reject" class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs text-violet-300">Reason (shown to the player)</label>
                    <input type="text" wire:model="rejectionReason" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                    @error('rejectionReason') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <button type="submit" wire:confirm="Reject this withdrawal and return the amount to the player?"
                    class="w-full rounded-lg bg-red-700 py-2 font-semibold text-white hover:bg-red-600">
                    Reject
                </button>
            </form>
        </div>
    @endif

    @if ($withdrawal->status === 'approved')
        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
            <button type="button" wire:click="markProcessing"
                class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
                Mark as processing
            </button>
        </div>
    @endif

    @if ($withdrawal->status === 'processing')
        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
            <h2 class="mb-4 text-lg font-bold text-violet-200">Mark as paid</h2>
            <form wire:submit="markPaid" class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs text-violet-300">Settlement reference (UTR / transaction ID)</label>
                    <input type="text" wire:model="settlementReference" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                    @error('settlementReference') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="w-full rounded-lg bg-emerald-700 py-2 font-semibold text-white hover:bg-emerald-600">
                    Mark as paid
                </button>
            </form>
        </div>

        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
            <h2 class="mb-4 text-lg font-bold text-violet-200">Mark as failed</h2>
            <form wire:submit="markFailed" class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs text-violet-300">Reason (shown to the player)</label>
                    <input type="text" wire:model="failureReason" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                    @error('failureReason') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <button type="submit" wire:confirm="Mark this payout as failed and return the amount to the player?"
                    class="w-full rounded-lg bg-red-700 py-2 font-semibold text-white hover:bg-red-600">
                    Mark as failed
                </button>
            </form>
        </div>
    @endif
</div>
