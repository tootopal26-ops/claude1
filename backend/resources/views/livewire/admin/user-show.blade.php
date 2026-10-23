@php
    $staff = auth('staff')->user();
    $wallet = $user->wallet;
    $balances = [
        ['label' => 'Total balance', 'value' => $wallet?->totalBalance() ?? '0.00', 'hint' => 'Deposit + eligible winnings'],
        ['label' => 'Deposit balance', 'value' => $wallet?->deposit_balance ?? '0.00', 'hint' => 'Spent on entry fees first'],
        ['label' => 'Eligible winnings', 'value' => $wallet?->eligible_winnings_balance ?? '0.00', 'hint' => 'Can be withdrawn'],
    ];
@endphp

<div>
    <x-ui.flash />

    <x-ui.page-header
        :title="$user->username"
        :description="$user->mobile.' · '.$user->email.' · Joined '.$user->created_at->format('d M Y')"
        :breadcrumbs="[
            ['label' => 'Players', 'url' => route('admin.users.index')],
            ['label' => $user->username],
        ]"
    >
        <x-slot:actions>
            @if ($user->is_banned)
                <x-ui.badge status="banned" class="px-3 py-1 text-sm" />
            @else
                <x-ui.badge status="active" class="px-3 py-1 text-sm" />
            @endif

            @if ($staff->hasPermission('users.ban'))
                <x-ui.button
                    wire:click="toggleBan"
                    :variant="$user->is_banned ? 'outline' : 'danger-ghost'"
                    size="sm"
                    loading="toggleBan"
                    :confirm="'Are you sure you want to '.($user->is_banned ? 'unban' : 'ban').' '.$user->username.'?'"
                    :confirm-title="$user->is_banned ? 'Unban player' : 'Ban player'"
                    :confirm-label="$user->is_banned ? 'Unban' : 'Ban'"
                >{{ $user->is_banned ? 'Unban account' : 'Ban account' }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ($balances as $balance)
            <div class="flex min-w-0 flex-col gap-2 rounded-xl border border-line bg-surface p-4">
                <p class="truncate text-xs font-medium text-fg-3">{{ $balance['label'] }}</p>
                <p class="flex items-center gap-2 text-2xl font-semibold tabular-nums tracking-tight text-fg"><x-coin-asset :size="22" />{{ $balance['value'] }}</p>
                <p class="text-xs leading-snug text-fg-3">{{ $balance['hint'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Read-only history --}}
        <div class="min-w-0 space-y-6 xl:col-span-2">
            <x-ui.card title="Recent wallet activity" description="The last 20 ledger entries, newest first" :padding="false">
                <x-ui.table class="rounded-none border-0">
                    <x-slot:head>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Balance</th>
                            <th class="text-right">Amount</th>
                            <th>Reason</th>
                        </tr>
                    </x-slot:head>

                    @forelse ($ledgerEntries as $entry)
                        @php($credit = $entry->isCredit())
                        <tr wire:key="ledger-{{ $entry->id }}">
                            <td class="whitespace-nowrap">{{ $entry->created_at->format('d M, H:i') }}</td>
                            <td class="whitespace-nowrap">{{ ucfirst(str_replace('_', ' ', $entry->type)) }}</td>
                            <td class="whitespace-nowrap">{{ ucfirst($entry->balance) }}</td>
                            <td class="whitespace-nowrap text-right">
                                <span @class(['inline-flex items-center gap-1.5 font-medium tabular-nums', 'text-success' => $credit, 'text-danger' => ! $credit])>
                                    <x-coin-asset :size="14" />{{ $credit ? '+' : '' }}{{ $entry->amount }}
                                </span>
                            </td>
                            <td>
                                @if ($entry->reason)
                                    <span class="block max-w-[11rem] truncate 2xl:max-w-[16rem]" title="{{ $entry->reason }}">{{ $entry->reason }}</span>
                                @else
                                    <span class="text-fg-3">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.table-empty :colspan="5" icon="wallet" title="No wallet activity yet" description="Deposits, entry fees, prizes and adjustments will be listed here." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Recent tournament entries" description="The last 10 tournaments this player joined" :padding="false">
                <x-ui.table class="rounded-none border-0">
                    <x-slot:head>
                        <tr>
                            <th>Tournament</th>
                            <th>Status</th>
                            <th class="text-right">Entry fee paid</th>
                            <th>Joined</th>
                        </tr>
                    </x-slot:head>

                    @forelse ($tournamentEntries as $entry)
                        <tr wire:key="entry-{{ $entry->id }}">
                            <td class="mm-cell-primary">
                                @if ($staff->hasPermission('tournaments.view'))
                                    <a href="{{ route('admin.tournaments.entries', $entry->tournament) }}" class="block max-w-[18rem] truncate hover:text-brand-text" title="{{ $entry->tournament->title }}">{{ $entry->tournament->title }}</a>
                                @else
                                    <span class="block max-w-[18rem] truncate" title="{{ $entry->tournament->title }}">{{ $entry->tournament->title }}</span>
                                @endif
                            </td>
                            <td><x-ui.badge :status="$entry->status" /></td>
                            <td class="whitespace-nowrap text-right">
                                <span class="inline-flex items-center gap-1.5 tabular-nums text-fg"><x-coin-asset :size="14" />{{ $entry->entry_fee_paid }}</span>
                            </td>
                            <td class="whitespace-nowrap">{{ $entry->created_at->format('d M, H:i') }}</td>
                        </tr>
                    @empty
                        <x-ui.table-empty :colspan="4" icon="tournaments" title="No tournament entries yet" description="Tournaments this player joins will be listed here." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>

        {{-- Modifying: kept apart from the read-only history above --}}
        @if ($staff->hasPermission('wallet.manage'))
            <div class="min-w-0">
                <x-ui.card title="Adjust wallet" description="Manually add or remove coins. Every adjustment needs a reason and is recorded in the audit log.">
                    <form wire:submit="adjustWallet" class="space-y-5" novalidate>
                        @if ($adjustError)
                            <x-ui.alert variant="danger" title="Adjustment not applied">{{ $adjustError }}</x-ui.alert>
                        @endif

                        <fieldset>
                            <legend class="mb-2 text-xs font-medium text-fg-2">Direction</legend>
                            <div class="flex gap-6">
                                <x-ui.radio wire:model="adjustDirection" name="adjustDirection" value="add" label="Add" />
                                <x-ui.radio wire:model="adjustDirection" name="adjustDirection" value="subtract" label="Subtract" />
                            </div>
                        </fieldset>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-1">
                            <x-ui.input label="Amount" wire:model="adjustAmount" type="text" inputmode="decimal" placeholder="0.00" required />

                            <x-ui.select label="Balance" wire:model="adjustBalance" required>
                                <option value="deposit">Deposit</option>
                                <option value="winnings">Eligible winnings</option>
                            </x-ui.select>
                        </div>

                        <x-ui.input label="Reason" wire:model="adjustReason" required hint="Required and audited. Be specific — reviewers read this." />

                        <p class="flex items-start gap-2 text-xs leading-relaxed text-fg-3">
                            <x-icon name="info" :size="14" class="mt-0.5 shrink-0" />
                            <span>Adjustments of {{ config('minimax.wallet_risk_alert_threshold') }} coins or more raise a risk alert for review.</span>
                        </p>

                        <x-ui.button
                            type="submit"
                            class="w-full"
                            loading="adjustWallet"
                            :confirm="'Apply this adjustment to '.$user->username.'\'s wallet? It is recorded in the audit log.'"
                            confirm-title="Apply wallet adjustment"
                            confirm-label="Apply adjustment"
                        >Apply adjustment</x-ui.button>
                    </form>
                </x-ui.card>
            </div>
        @endif
    </div>
</div>
