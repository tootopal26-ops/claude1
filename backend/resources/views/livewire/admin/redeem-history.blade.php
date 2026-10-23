<div>
    <x-ui.page-header
        title="Redemption history"
        description="Every attempt to redeem a code, with the player's username and the result. Mobile numbers and wallet details are not shown here."
        :breadcrumbs="[
            ['label' => 'Redeem codes', 'url' => portal_route('redeem-codes.index')],
            ['label' => 'History'],
        ]"
    />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full max-w-xs">
            <x-ui.input type="search" label="Search" wire:model.live.debounce.300ms="search" placeholder="Code, player or campaign" />
        </div>
        <div class="w-full max-w-[12rem]">
            <x-ui.select label="Result" wire:model.live="result">
                <option value="all">All attempts</option>
                <option value="success">Successful</option>
                <option value="failed">Not redeemed</option>
            </x-ui.select>
        </div>
    </div>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>When</th>
                <th>Player</th>
                <th>Campaign</th>
                <th>Code entered</th>
                <th>Result</th>
                <th class="text-right">Coins</th>
            </tr>
        </x-slot:head>

        @forelse ($attempts as $attempt)
            @php($labels = ['success' => 'Redeemed', 'invalid_format' => 'Wrong format', 'invalid_code' => 'Not a code', 'inactive' => 'Inactive', 'expired' => 'Expired', 'limit_reached' => 'Fully redeemed', 'already_redeemed' => 'Already redeemed', 'ineligible' => 'Not eligible', 'error' => 'Error'])
            <tr wire:key="attempt-{{ $attempt->id }}">
                <td><x-ui.moment :at="$attempt->created_at" /></td>
                <td class="mm-cell-primary"><span class="block max-w-[14rem] truncate">{{ $attempt->user?->username ?? '—' }}</span></td>
                <td class="text-fg-2">
                    @if ($attempt->campaign)
                        <span class="block max-w-[14rem] truncate" title="{{ $attempt->campaign->name }}">{{ $attempt->campaign->name }}</span>
                    @else
                        —
                    @endif
                </td>
                <td><code class="font-mono text-xs tracking-widest text-fg-2">{{ $attempt->submitted_code }}</code></td>
                <td>
                    <x-ui.badge :variant="$attempt->result === 'success' ? 'success' : ($attempt->result === 'error' ? 'danger' : 'warning')">{{ $labels[$attempt->result] ?? $attempt->result }}</x-ui.badge>
                </td>
                <td class="whitespace-nowrap text-right tabular-nums">
                    @if ($attempt->result === 'success' && $attempt->campaign)
                        <span class="inline-flex items-center gap-1.5 text-success"><x-coin-asset :size="14" />{{ number_format($attempt->campaign->reward_coins) }}</span>
                    @else
                        <span class="text-fg-3">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="6" icon="clock" title="No redemption attempts yet" description="Attempts appear here as soon as a player enters a code in the app." />
        @endforelse

        <x-slot:footer>{{ $attempts->links() }}</x-slot:footer>
    </x-ui.table>
</div>
