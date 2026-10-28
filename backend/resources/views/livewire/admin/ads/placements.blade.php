<div>
    <x-ui.flash />

    <x-ui.page-header title="Ad placements" description="Switch each placement on or off independently. Ads are always labelled in the app and never replace a Join, Rules, Room details or Results action." />

    @include('livewire.admin.ads._nav')

    @if (! $masterEnabled)
        <x-ui.alert variant="warning" class="mb-4">"All ads" is off on the Ads Earning page, so no placement runs, whatever its switch says.</x-ui.alert>
    @elseif (! $rewardedEnabled)
        <x-ui.alert variant="warning" class="mb-4">Rewarded Ads is disabled, so rewarded placements don't run even when switched on here.</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        @foreach ($placements as $placement)
            @php($metric = $metrics[$placement->key] ?? null)
            <x-ui.card :title="$placement->name" :description="$placement->description" wire:key="placement-{{ $placement->id }}">
                <x-slot:actions>
                    <x-ui.badge variant="neutral" :dot="false">{{ ucfirst(str_replace('_', '-', $placement->format)) }}</x-ui.badge>
                    @if (! $placement->is_supported)
                        <x-ui.badge variant="danger" :dot="false">Not supported</x-ui.badge>
                    @else
                        <x-ui.badge :status="$placement->is_enabled ? 'active' : 'inactive'">{{ $placement->is_enabled ? 'On' : 'Off' }}</x-ui.badge>
                    @endif
                </x-slot:actions>

                @if ($placement->is_supported)
                    <form wire:submit="save({{ $placement->id }})" class="grid grid-cols-1 gap-3 sm:grid-cols-2" novalidate>
                        <div class="sm:col-span-2"><x-ui.switch wire:model="rows.{{ $placement->id }}.is_enabled" label="Enabled" /></div>
                        <x-ui.select label="Ad unit" wire:model="rows.{{ $placement->id }}.ad_unit_id">
                            <option value="">None</option>
                            @foreach ($units->where('format', $placement->format) as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->environment }}){{ $unit->is_active ? '' : ' — inactive' }}</option>
                            @endforeach
                        </x-ui.select>
                        @if ($placement->format === 'native' && str_contains($placement->key, 'feed'))
                            <x-ui.select label="Insert an ad" wire:model="rows.{{ $placement->id }}.insert_every">
                                <option value="">Disabled</option>
                                @foreach (\App\Livewire\Admin\Ads\Placements::FEED_INTERVALS as $interval)
                                    <option value="{{ $interval }}">After every {{ $interval }} {{ $placement->key === 'leaderboard_feed' ? 'players' : 'tournaments' }}</option>
                                @endforeach
                            </x-ui.select>
                        @endif
                        @if ($placement->format === 'interstitial')
                            <x-ui.input label="Per player per day" type="number" min="1" wire:model="rows.{{ $placement->id }}.daily_cap" optional placeholder="No cap" />
                            <x-ui.input label="Cooldown (seconds)" type="number" min="0" wire:model="rows.{{ $placement->id }}.cooldown" optional placeholder="None" hint="Minimum time between two of these ads." />
                        @endif
                        @error('rows.'.$placement->id.'.is_enabled')<p class="text-xs text-danger sm:col-span-2" role="alert">{{ $message }}</p>@enderror
                        <div class="flex items-end justify-end sm:col-span-2">
                            <x-ui.button type="submit" size="sm" loading="save({{ $placement->id }})" icon="check">Save</x-ui.button>
                        </div>
                    </form>
                @endif

                @if ($metric)
                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 border-t border-line pt-3 text-xs sm:grid-cols-4">
                        <div><dt class="text-fg-3">Requests (7 d)</dt><dd class="tabular-nums text-fg">{{ number_format($metric['requests']) }}</dd></div>
                        <div><dt class="text-fg-3">Fill rate</dt><dd class="tabular-nums text-fg">{{ $metric['fill_rate'] === null ? '—' : $metric['fill_rate'].'%' }}</dd></div>
                        <div><dt class="text-fg-3">No-fill / errors</dt><dd class="tabular-nums text-fg">{{ number_format($metric['no_fill']) }} / {{ number_format($metric['errors']) }}</dd></div>
                        <div><dt class="text-fg-3">Impressions</dt><dd class="tabular-nums text-fg">{{ number_format($metric['impressions']) }}</dd></div>
                    </dl>
                @endif
            </x-ui.card>
        @endforeach
    </div>
</div>
