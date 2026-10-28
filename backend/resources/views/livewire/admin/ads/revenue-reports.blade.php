<div>
    <x-ui.flash />

    <x-ui.page-header title="Revenue reports" description="The provider's revenue figures. Only finalized Ads Earning revenue is shared with players; app-reported estimates are shown beside each report for reconciliation and are never paid out." />

    @include('livewire.admin.ads._nav')

    @if ($canManage)
        <form wire:submit="add" novalidate>
            <x-ui.form-section title="Enter a provider figure" description="From the LevelPlay dashboard (Reports) or its export, in US dollars, for whole days in {{ config('minimax.ads_earning_timezone') }}.">
                <x-ui.input label="First day" type="date" wire:model="periodStart" required />
                <x-ui.input label="Last day" type="date" wire:model="periodEnd" required />
                <x-ui.select label="Placement" wire:model="placementKey" hint="Weekly settlements use only the Ads Earning placement's revenue.">
                    <option value="">All placements (reporting only)</option>
                    @foreach ($placements as $placement)
                        <option value="{{ $placement->key }}">{{ $placement->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="Revenue (USD)" type="text" inputmode="decimal" wire:model="revenue" required placeholder="e.g. 12.345678" />
                <x-ui.input label="Source" wire:model="source" required maxlength="120" placeholder="e.g. LevelPlay dashboard, report of 21 Oct" wrapperClass="sm:col-span-2" />
                <x-ui.textarea label="Notes" wire:model="notes" optional rows="2" />
            </x-ui.form-section>
            <div class="mb-6 flex justify-end"><x-ui.button type="submit" loading="add" icon="plus">Add as estimated</x-ui.button></div>
        </form>
    @else
        <x-ui.alert variant="info" class="mb-4">Read-only: entering, finalizing and disputing revenue needs the "Configure ads" permission.</x-ui.alert>
    @endif

    @error('reports')<x-ui.alert variant="danger" class="mb-4">{{ $message }}</x-ui.alert>@enderror

    <div class="mb-3 max-w-xs">
        <x-ui.select label="Status" wire:model.live="statusFilter">
            <option value="">All statuses</option>
            <option value="estimated">Estimated</option>
            <option value="finalized">Finalized</option>
            <option value="disputed">Disputed</option>
        </x-ui.select>
    </div>

    <x-ui.table label="Revenue reports">
        <x-slot:head><tr><th>Days</th><th>Placement</th><th class="text-right">Provider revenue</th><th class="text-right">App estimate</th><th>Status</th><th>Source</th><th>Entered by</th>@if ($canManage)<th class="text-right">Actions</th>@endif</tr></x-slot:head>
        @forelse ($reports as $report)
            <tr wire:key="rev-{{ $report->id }}">
                <td class="whitespace-nowrap">{{ $report->period_start->format('d M') }} – {{ $report->period_end->format('d M Y') }}</td>
                <td>{{ $placements->firstWhere('key', $report->placement_key)?->name ?? 'All placements' }}</td>
                <td class="text-right tabular-nums">${{ number_format((float) $report->revenue_usd, 4) }}</td>
                <td class="text-right tabular-nums text-fg-3">${{ number_format((float) $estimates[$report->id], 4) }}</td>
                <td><x-ui.badge :variant="['estimated' => 'warning', 'finalized' => 'success', 'disputed' => 'danger'][$report->status]">{{ ucfirst($report->status) }}</x-ui.badge></td>
                <td><span class="block max-w-[16rem] truncate" title="{{ $report->source }}{{ $report->notes ? ' — '.$report->notes : '' }}">{{ $report->source }}</span></td>
                <td class="whitespace-nowrap text-fg-3">{{ $report->enteredBy?->name ?? '—' }}</td>
                @if ($canManage)
                    <td class="whitespace-nowrap text-right">
                        @if ($report->status === 'estimated')
                            <x-ui.button size="sm" variant="ghost" wire:click="finalize({{ $report->id }})" confirm="Finalize this figure? Settlements can then use it, and it can no longer be edited." confirm-title="Finalize revenue" confirm-label="Finalize">Finalize</x-ui.button>
                        @endif
                        @if ($report->status !== 'disputed')
                            <x-ui.button size="sm" variant="danger-ghost" wire:click="dispute({{ $report->id }})" confirm="Mark this report disputed? It will be excluded from settlements." confirm-title="Dispute revenue" confirm-label="Mark disputed">Dispute</x-ui.button>
                        @endif
                    </td>
                @endif
            </tr>
        @empty
            <x-ui.table-empty :colspan="$canManage ? 8 : 7" icon="leaderboard" title="No revenue reports yet" description="No provider revenue has been entered. Nothing can be settled until a finalized Ads Earning figure exists." />
        @endforelse
        <x-slot:footer>{{ $reports->links() }}</x-slot:footer>
    </x-ui.table>
</div>
