<div>
    <x-ui.flash />

    <x-ui.page-header title="Deposit Bonus Management" description="A one-time bonus credited to the Deposit Wallet when a player makes their first eligible deposit — verified by the payment gateway's webhook, never by a frontend success screen." />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.card>
            <p class="text-xs font-medium text-fg-3">Total bonus coins distributed</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-fg">{{ number_format((float) $stats['total_amount'], 2) }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium text-fg-3">Players who received it</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-fg">{{ number_format($stats['players']) }}</p>
        </x-ui.card>
    </div>

    <form wire:submit="save" novalidate>
        @error('values')<x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror
        <x-ui.form-section title="Settings" description="Off by default. The amount and minimum eligible deposit take effect immediately for any deposit confirmed after saving — awards already made are never rewritten.">
            @include('livewire.admin.ads._setting', ['key' => 'deposit_bonus_enabled'])
            @include('livewire.admin.ads._setting', ['key' => 'deposit_bonus_first_deposit_only', 'hint' => 'On: at most one bonus per player, ever. Off: a player can receive the bonus on further deposits up to the lifetime cap below.'])
            @include('livewire.admin.ads._setting', ['key' => 'deposit_bonus_amount', 'hint' => 'Coins credited to the Deposit Wallet per eligible deposit.'])
            @include('livewire.admin.ads._setting', ['key' => 'deposit_bonus_min_deposit', 'hint' => 'A deposit below this amount never qualifies.'])
            @include('livewire.admin.ads._setting', ['key' => 'deposit_bonus_max_per_player', 'hint' => 'Lifetime cap on bonus coins per player — leave blank for no cap. Only applies when "first eligible deposit only" is off.'])
        </x-ui.form-section>
        <div class="mb-6 flex justify-end"><x-ui.button type="submit" loading="save" icon="check">Save settings</x-ui.button></div>
    </form>

    <h2 class="mb-2 text-sm font-semibold text-fg">Award history</h2>
    <x-ui.table label="Deposit bonus awards">
        <x-slot:head><tr><th>Player</th><th>Amount</th><th>Deposit order</th><th>Awarded</th></tr></x-slot:head>
        @forelse ($awards as $award)
            <tr wire:key="award-{{ $award->id }}">
                <td class="mm-cell-primary">{{ $award->user?->username ?? 'Deleted player' }}</td>
                <td class="tabular-nums">{{ number_format((float) $award->amount, 2) }} coins</td>
                <td class="whitespace-nowrap text-fg-3">{{ $award->depositOrder?->our_order_id ?? '—' }}</td>
                <td class="whitespace-nowrap text-fg-3">{{ $award->created_at->format('d M Y, H:i') }}</td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="4" icon="deposit" title="No bonuses awarded yet" />
        @endforelse
    </x-ui.table>
    @if ($awards->hasPages())
        <div class="mt-4">{{ $awards->links() }}</div>
    @endif

    @include('livewire.admin.ads._history')
</div>
