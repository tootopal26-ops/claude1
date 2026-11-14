<div>
    <x-ui.flash />

    <x-ui.page-header title="Referral Management" description="Every completed referral — both players and both reward amounts." />

    @include('livewire.admin.referrals._nav')

    <x-ui.input type="search" wire:model.live.debounce.400ms="search" placeholder="Search by username, mobile or referral code" class="mb-4 max-w-sm" aria-label="Search referrals" />

    <x-ui.table label="Referral history">
        <x-slot:head><tr><th>Referrer</th><th>Referred player</th><th>Code</th><th class="text-right">New player reward</th><th class="text-right">Referrer reward</th><th>Status</th><th>When</th></tr></x-slot:head>
        @forelse ($referrals as $referral)
            <tr wire:key="ref-{{ $referral->id }}">
                <td class="mm-cell-primary">{{ $referral->referrer?->username ?? '—' }}</td>
                <td>{{ $referral->referred?->username ?? '—' }}</td>
                <td class="font-mono text-xs">{{ $referral->referral_code_used }}</td>
                <td class="text-right tabular-nums">{{ number_format((float) $referral->new_player_reward, 2) }}</td>
                <td class="text-right tabular-nums">{{ number_format((float) $referral->referrer_reward, 2) }}</td>
                <td><x-ui.badge :status="$referral->status" /></td>
                <td class="whitespace-nowrap text-fg-3">{{ $referral->created_at->format('d M Y, H:i') }}</td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="7" icon="users" title="No referrals yet" />
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $referrals->links() }}</div>
</div>
