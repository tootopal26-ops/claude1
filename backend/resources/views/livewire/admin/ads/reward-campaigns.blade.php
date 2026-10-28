<div>
    <x-ui.flash />

    <x-ui.page-header title="Reward campaigns" description="A fixed reward for one complete rewarded ad, shown to players as a Claim button on the Ads Earning screen. Credited only after the ad network verifies the ad." />

    @include('livewire.admin.ads._nav')

    @unless ($placementLive)
        <x-ui.alert variant="warning" class="mb-4">The "Reward campaigns" placement isn't live (switched off, no active ad unit, or Rewarded Ads/All ads off), so players don't see any campaign yet.</x-ui.alert>
    @endunless

    <form wire:submit="save" novalidate>
        <x-ui.form-section :title="$editingId ? 'Edit campaign' : 'New campaign'" description="Changes apply to ads started from now on; rewards already credited are never changed.">
            <x-ui.input label="Name (shown to players)" wire:model="name" required maxlength="80" placeholder="e.g. Weekend bonus" />
            <x-ui.input label="Reward per verified ad (coins)" wire:model="rewardCoins" inputmode="decimal" required placeholder="e.g. 2.50" />
            <x-ui.input label="Per player per day" type="number" min="1" max="50" wire:model="dailyLimit" required />
            <x-ui.input label="Total budget (coins)" wire:model="budget" inputmode="decimal" optional placeholder="No budget cap" hint="The campaign stops granting once this is used up." />
            <x-ui.input label="Starts" type="datetime-local" wire:model="startsAt" optional />
            <x-ui.input label="Ends" type="datetime-local" wire:model="endsAt" optional />
            <div class="sm:col-span-2"><x-ui.switch wire:model="isActive" label="Active" /></div>
        </x-ui.form-section>
        <div class="mb-6 flex justify-end gap-2">
            @if ($editingId)<x-ui.button variant="ghost" wire:click="cancel">Cancel</x-ui.button>@endif
            <x-ui.button type="submit" loading="save" icon="check">{{ $editingId ? 'Save campaign' : 'Create campaign' }}</x-ui.button>
        </div>
    </form>

    <x-ui.table label="Reward campaigns">
        <x-slot:head><tr><th>Campaign</th><th class="text-right">Reward</th><th class="text-right">Per day</th><th class="text-right">Budget used</th><th class="text-right">Ads started / credited</th><th>Window</th><th>Status</th><th class="text-right">Actions</th></tr></x-slot:head>
        @forelse ($campaigns as $campaign)
            <tr wire:key="camp-{{ $campaign->id }}">
                <td class="mm-cell-primary">{{ $campaign->name }}</td>
                <td class="text-right tabular-nums">{{ number_format((float) $campaign->reward_coins, 2) }}</td>
                <td class="text-right tabular-nums">{{ $campaign->daily_limit_per_user }}</td>
                <td class="text-right tabular-nums">{{ number_format((float) $campaign->credited_coins, 2) }}{{ $campaign->budget_coins !== null ? ' / '.number_format((float) $campaign->budget_coins, 2) : '' }}</td>
                <td class="text-right tabular-nums">{{ (int) ($claims[$campaign->id]->started ?? 0) }} / {{ (int) ($claims[$campaign->id]->credited ?? 0) }}</td>
                <td class="whitespace-nowrap text-fg-3">{{ $campaign->starts_at?->format('d M H:i') ?? 'Now' }} – {{ $campaign->ends_at?->format('d M H:i') ?? 'open' }}</td>
                <td><x-ui.badge :status="$campaign->is_active ? 'active' : 'inactive'" /></td>
                <td class="whitespace-nowrap text-right">
                    <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $campaign->id }})">Edit</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" wire:click="toggle({{ $campaign->id }})">{{ $campaign->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="8" icon="ticket" title="No reward campaigns" description="Ads Earning (revenue share) works without any campaign; campaigns are an optional fixed-reward extra." />
        @endforelse
    </x-ui.table>
</div>
