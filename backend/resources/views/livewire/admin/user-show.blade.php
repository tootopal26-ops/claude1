<div class="mx-auto max-w-3xl space-y-6">
    <a href="{{ route('admin.users.index') }}" class="text-sm text-violet-400 hover:text-violet-200">&larr; Back to players</a>

    @if (session('status'))
        <div class="rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-white">{{ $user->username }}</h1>
                <p class="text-sm text-violet-400">{{ $user->mobile }} · {{ $user->email }}</p>
                <p class="text-xs text-violet-500">Joined {{ $user->created_at->format('d M Y') }}</p>
            </div>
            <div class="text-right">
                @if ($user->is_banned)
                    <span class="rounded-full bg-red-900/40 px-3 py-1 text-xs font-semibold text-red-300">Banned</span>
                @else
                    <span class="rounded-full bg-emerald-900/40 px-3 py-1 text-xs font-semibold text-emerald-300">Active</span>
                @endif
                @if (auth('staff')->user()->hasPermission('users.ban'))
                    <div class="mt-2">
                        <button type="button" wire:click="toggleBan"
                            wire:confirm="Are you sure you want to {{ $user->is_banned ? 'unban' : 'ban' }} {{ $user->username }}?"
                            class="text-xs {{ $user->is_banned ? 'text-emerald-400 hover:text-emerald-300' : 'text-red-400 hover:text-red-300' }}">
                            {{ $user->is_banned ? 'Unban account' : 'Ban account' }}
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-4">
        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-4">
            <p class="text-xs text-violet-400">Total balance</p>
            <p class="flex items-center gap-1.5 text-xl font-bold text-white"><x-coin-asset :size="18" />{{ $user->wallet?->totalBalance() ?? '0.00' }}</p>
        </div>
        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-4">
            <p class="text-xs text-violet-400">Deposit balance</p>
            <p class="flex items-center gap-1.5 text-xl font-bold text-white"><x-coin-asset :size="18" />{{ $user->wallet?->deposit_balance ?? '0.00' }}</p>
        </div>
        <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-4">
            <p class="text-xs text-violet-400">Eligible winnings</p>
            <p class="flex items-center gap-1.5 text-xl font-bold text-emerald-400"><x-coin-asset :size="18" />{{ $user->wallet?->eligible_winnings_balance ?? '0.00' }}</p>
        </div>
    </div>

    @if (auth('staff')->user()->hasPermission('wallet.manage'))
    <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <h2 class="mb-4 text-lg font-bold text-violet-200">Adjust wallet</h2>
        <form wire:submit="adjustWallet" class="space-y-4">
            <div class="flex gap-4 text-sm text-violet-200">
                <label class="flex items-center gap-2">
                    <input type="radio" wire:model="adjustDirection" value="add"> Add
                </label>
                <label class="flex items-center gap-2">
                    <input type="radio" wire:model="adjustDirection" value="subtract"> Subtract
                </label>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs text-violet-300">Amount</label>
                    <input type="text" wire:model="adjustAmount" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                    @error('adjustAmount') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs text-violet-300">Balance</label>
                    <select wire:model="adjustBalance" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                        <option value="deposit">Deposit</option>
                        <option value="winnings">Eligible winnings</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs text-violet-300">Reason (required, audited)</label>
                <input type="text" wire:model="adjustReason" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('adjustReason') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            @if ($adjustError)
                <p class="text-sm text-red-400">{{ $adjustError }}</p>
            @endif

            <p class="text-xs text-violet-500">Adjustments of {{ config('minimax.wallet_risk_alert_threshold') }} coins or more raise a risk alert for review.</p>

            <button type="submit" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
                Apply adjustment
            </button>
        </form>
    </div>
    @endif

    <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <h2 class="mb-4 text-lg font-bold text-violet-200">Recent wallet activity</h2>
        <table class="w-full text-left text-sm">
            <thead class="text-violet-400">
                <tr>
                    <th class="py-2">When</th>
                    <th class="py-2">Type</th>
                    <th class="py-2">Balance</th>
                    <th class="py-2">Amount</th>
                    <th class="py-2">Reason</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/20">
                @forelse ($ledgerEntries as $entry)
                    <tr>
                        <td class="py-2 text-violet-300">{{ $entry->created_at->format('d M, H:i') }}</td>
                        <td class="py-2 text-violet-300">{{ $entry->type }}</td>
                        <td class="py-2 text-violet-300">{{ $entry->balance }}</td>
                        <td class="py-2 {{ $entry->isCredit() ? 'text-emerald-400' : 'text-red-400' }}">{{ $entry->amount }}</td>
                        <td class="py-2 text-violet-500">{{ $entry->reason ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-6 text-center text-violet-400">No wallet activity yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <h2 class="mb-4 text-lg font-bold text-violet-200">Recent tournament entries</h2>
        <table class="w-full text-left text-sm">
            <thead class="text-violet-400">
                <tr>
                    <th class="py-2">Tournament</th>
                    <th class="py-2">Status</th>
                    <th class="py-2">Entry fee paid</th>
                    <th class="py-2">Joined</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/20">
                @forelse ($tournamentEntries as $entry)
                    <tr>
                        <td class="py-2 text-white">{{ $entry->tournament->title }}</td>
                        <td class="py-2 text-violet-300">{{ $entry->status }}</td>
                        <td class="py-2 text-violet-300">{{ $entry->entry_fee_paid }}</td>
                        <td class="py-2 text-violet-300">{{ $entry->created_at->format('d M, H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-6 text-center text-violet-400">No tournament entries yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
