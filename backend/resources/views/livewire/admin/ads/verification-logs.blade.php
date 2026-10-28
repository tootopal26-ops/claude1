<div>
    <x-ui.page-header title="Verification logs" description="Every reward callback the ad network sent the server, with its signature check and outcome. A reward is granted only on a valid, first-time callback for a pending claim." />

    @include('livewire.admin.ads._nav')

    @if (! config('minimax.levelplay_s2s_private_key'))
        <x-ui.alert variant="warning" class="mb-4">No LevelPlay server-to-server private key is configured (LEVELPLAY_S2S_PRIVATE_KEY), so every callback is refused and no production reward can be verified.</x-ui.alert>
    @endif

    <x-ui.card class="mb-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.select label="Outcome" wire:model.live="outcome">
                <option value="">All outcomes</option>
                @foreach ($outcomes as $option)
                    <option value="{{ $option }}">{{ str_replace('_', ' ', $option) }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Signature" wire:model.live="signature">
                <option value="">Any</option>
                <option value="valid">Valid</option>
                <option value="invalid">Invalid</option>
            </x-ui.select>
            <x-ui.input label="From" type="date" wire:model.live="dateFrom" />
            <x-ui.input label="To" type="date" wire:model.live="dateTo" />
        </div>
    </x-ui.card>

    <x-ui.table label="Callback logs">
        <x-slot:head><tr><th>Received</th><th>Provider event</th><th>Signature</th><th>Outcome</th><th>Claim</th><th>Source IP</th></tr></x-slot:head>
        @forelse ($logs as $log)
            <tr wire:key="cb-{{ $log->id }}">
                <td class="whitespace-nowrap"><time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->format('d M H:i:s') }}</time></td>
                <td class="font-mono text-xs"><span class="block max-w-[14rem] truncate" title="{{ $log->event_id }}">{{ $log->event_id ?? '—' }}</span></td>
                <td><x-ui.badge :variant="$log->signature_valid ? 'success' : 'danger'">{{ $log->signature_valid ? 'Valid' : 'Invalid' }}</x-ui.badge></td>
                <td><x-ui.badge :variant="in_array($log->outcome, ['verified'], true) ? 'success' : (in_array($log->outcome, ['duplicate'], true) ? 'info' : 'warning')" :dot="false">{{ str_replace('_', ' ', $log->outcome) }}</x-ui.badge></td>
                <td>{{ $log->rewardedClaim ? (\App\Domain\Ads\AdsAnalytics::REWARD_TYPES[$log->rewardedClaim->purpose] ?? $log->rewardedClaim->purpose).' · player #'.$log->rewardedClaim->user_id : '—' }}</td>
                <td class="font-mono text-xs text-fg-3">{{ $log->ip_address ?? '—' }}</td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="6" icon="shield" title="No callbacks received" description="Callbacks arrive at /api/ads/levelplay/callback once LevelPlay server-to-server rewards are set up." />
        @endforelse
        <x-slot:footer>{{ $logs->links() }}</x-slot:footer>
    </x-ui.table>
</div>
