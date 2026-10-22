<div class="mx-auto max-w-2xl space-y-6">
    <a href="{{ route('admin.staff-salary.settlements.index') }}" class="text-sm text-violet-400 hover:text-violet-200">&larr; Back to settlements</a>

    @if (session('status'))
        <div class="rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    @if ($actionError)
        <div class="rounded-lg bg-red-900/40 px-4 py-3 text-sm text-red-300">{{ $actionError }}</div>
    @endif

    <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <h1 class="text-2xl font-bold text-white">{{ $settlement->staffUser->display_name }}</h1>
        <p class="mt-1 text-sm text-violet-400">
            Week of {{ $settlement->period_start->format('d M Y') }} &ndash; {{ $settlement->period_end->copy()->subDay()->format('d M Y') }}
        </p>

        <div class="mt-4 grid grid-cols-2 gap-4">
            <div>
                <p class="text-xs text-violet-400">Total</p>
                <p class="text-xl font-bold text-emerald-400">{{ $settlement->total_amount }}</p>
            </div>
            <div>
                <p class="text-xs text-violet-400">Status</p>
                <p class="text-xl font-bold text-violet-300">{{ ucfirst($settlement->status) }}</p>
            </div>
        </div>

        @if ($settlement->isPaid())
            <p class="mt-4 text-sm text-emerald-400">
                Paid with reference "{{ $settlement->payment_reference }}" by {{ $settlement->paidByStaff?->display_name }}
                on {{ $settlement->paid_at?->format('d M Y, H:i') }}.
            </p>
        @endif
    </div>

    @if (! $settlement->isPaid() && auth('staff')->user()->hasPermission('salary.approve'))
        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
            <h2 class="mb-4 text-lg font-bold text-violet-200">Mark as paid</h2>
            <form wire:submit="markPaid" class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs text-violet-300">Payment reference</label>
                    <input type="text" wire:model="paymentReference" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                    @error('paymentReference') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <button type="submit" wire:confirm="Mark this settlement as paid?" class="w-full rounded-lg bg-emerald-700 py-2 font-semibold text-white hover:bg-emerald-600">
                    Mark as paid
                </button>
            </form>
        </div>
    @endif

    <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <h2 class="mb-4 text-lg font-bold text-violet-200">Line items</h2>
        <table class="w-full text-left text-sm">
            <thead class="text-violet-400">
                <tr>
                    <th class="px-4 py-2">Action</th>
                    <th class="px-4 py-2">Occurred</th>
                    <th class="px-4 py-2">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/20">
                @forelse ($items as $item)
                    <tr>
                        <td class="px-4 py-2 text-violet-300">{{ $item->event->action_type }}</td>
                        <td class="px-4 py-2 text-violet-300">{{ $item->event->occurred_at->format('d M, H:i') }}</td>
                        <td class="px-4 py-2 text-emerald-400">{{ $item->amount }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-violet-400">No line items.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
