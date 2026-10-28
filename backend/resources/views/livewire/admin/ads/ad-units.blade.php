<div>
    <x-ui.flash />

    <x-ui.page-header title="Ad units" description="The LevelPlay ad unit IDs the app loads. AdMob and Unity Ads instances are set up inside the LevelPlay dashboard; the reference fields only record them." />

    @include('livewire.admin.ads._nav')

    <x-ui.alert variant="info" class="mb-4">Ad unit IDs are public (they ship inside the app). Never paste a secret here — the LevelPlay server-to-server private key belongs only in the server's environment (LEVELPLAY_S2S_PRIVATE_KEY). Use test units (environment "development") with test mode on until production approval.</x-ui.alert>

    <form wire:submit="save" novalidate>
        <x-ui.form-section :title="$editingId ? 'Edit ad unit' : 'Add an ad unit'" description="One unit per format and environment is typical.">
            <x-ui.input label="Name" wire:model="name" required maxlength="80" placeholder="e.g. Rewarded — production" />
            <x-ui.select label="Format" wire:model="format" required>
                <option value="rewarded">Rewarded</option>
                <option value="interstitial">Interstitial</option>
                <option value="banner">Banner</option>
                <option value="native">Native</option>
            </x-ui.select>
            <x-ui.select label="Environment" wire:model="environment" required>
                <option value="development">Development (test)</option>
                <option value="staging">Staging</option>
                <option value="production">Production</option>
            </x-ui.select>
            <x-ui.input label="LevelPlay ad unit ID" wire:model="levelplayAdUnitId" required autocomplete="off" spellcheck="false" />
            <x-ui.input label="AdMob reference" wire:model="admobReference" optional autocomplete="off" spellcheck="false" hint="e.g. the AdMob ad unit ID behind this LevelPlay unit." />
            <x-ui.input label="Unity Ads reference" wire:model="unityReference" optional autocomplete="off" spellcheck="false" hint="e.g. the Unity Ads placement ID." />
            <div class="sm:col-span-2"><x-ui.switch wire:model="isActive" label="Active" hint="Inactive units are never sent to the app." /></div>
        </x-ui.form-section>
        <div class="mb-6 flex justify-end gap-2">
            @if ($editingId)<x-ui.button variant="ghost" wire:click="cancel">Cancel</x-ui.button>@endif
            <x-ui.button type="submit" loading="save" icon="check">{{ $editingId ? 'Save ad unit' : 'Add ad unit' }}</x-ui.button>
        </div>
    </form>

    <x-ui.card class="mb-4">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
            <x-ui.kv label="Last SDK initialization (app-reported)">
                @if ($lastInit)
                    <x-ui.badge :variant="$lastInit->event === 'init_success' ? 'success' : 'danger'">{{ $lastInit->event === 'init_success' ? 'Succeeded' : 'Failed'.($lastInit->error_code ? ' (code '.$lastInit->error_code.')' : '') }}</x-ui.badge>
                    <span class="text-fg-3">{{ $lastInit->occurred_at->format('d M Y, H:i') }}{{ $lastInit->is_test ? ' · test' : '' }}</span>
                @else
                    <span class="text-fg-3">No app has reported an initialization yet.</span>
                @endif
            </x-ui.kv>
            <x-ui.kv label="AdMob App ID"><span class="text-fg-3">Set at Android build time (LEVELPLAY_ADMOB_APP_ID), not here.</span></x-ui.kv>
            <x-ui.kv label="Test mode"><x-ui.badge :variant="config('minimax.ads_test_mode') ? 'info' : 'neutral'">{{ config('minimax.ads_test_mode') ? 'On — events count as test' : 'Off' }}</x-ui.badge></x-ui.kv>
        </dl>
    </x-ui.card>

    <x-ui.table label="Ad units">
        <x-slot:head><tr><th>Name</th><th>Format</th><th>Environment</th><th>LevelPlay ID</th><th>Live in the app</th><th>Last load</th><th class="text-right">Loads / no-fill / errors (7 d)</th><th>Last error</th><th>Status</th><th>Updated</th><th class="text-right">Actions</th></tr></x-slot:head>
        @forelse ($units as $unit)
            @php($s = $stats[$unit->levelplay_ad_unit_id] ?? collect())
            <tr wire:key="unit-{{ $unit->id }}">
                <td class="mm-cell-primary">{{ $unit->name }}</td>
                <td>{{ ucfirst($unit->format) }}</td>
                <td><x-ui.badge :variant="$unit->environment === 'production' ? 'brand' : 'neutral'" :dot="false">{{ ucfirst($unit->environment) }}</x-ui.badge></td>
                <td class="font-mono text-xs">{{ $unit->levelplay_ad_unit_id }}</td>
                <td>
                    @if ($livePlacements[$unit->id])
                        <x-ui.badge variant="success">{{ implode(', ', $livePlacements[$unit->id]) }}</x-ui.badge>
                    @else
                        <span class="text-fg-3">{{ $unit->placements->isEmpty() ? 'Not used by a placement' : 'Not live' }}</span>
                    @endif
                </td>
                <td class="whitespace-nowrap text-fg-3">{{ $unit->last_loaded_at?->format('d M H:i') ?? '—' }}</td>
                <td class="whitespace-nowrap text-right tabular-nums">{{ (int) ($s['loaded'] ?? 0) }} / {{ (int) ($s['no_fill'] ?? 0) }} / {{ (int) ($s['load_failed'] ?? 0) + (int) ($s['display_failed'] ?? 0) }}</td>
                <td><span class="block max-w-[12rem] truncate text-fg-3" title="{{ $unit->last_error }}">{{ $unit->last_error_at ? $unit->last_error_at->format('d M H:i').' · '.$unit->last_error : '—' }}</span></td>
                <td><x-ui.badge :status="$unit->is_active ? 'active' : 'inactive'" /></td>
                <td class="whitespace-nowrap text-fg-3" title="Created {{ $unit->created_at->format('d M Y H:i') }}">{{ $unit->updated_at->format('d M Y H:i') }}</td>
                <td class="whitespace-nowrap text-right">
                    <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $unit->id }})">Edit</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" wire:click="toggle({{ $unit->id }})" loading="toggle">{{ $unit->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="11" icon="presets" title="No ad units yet" description="Add the LevelPlay ad unit IDs from your LevelPlay dashboard." />
        @endforelse
    </x-ui.table>
    <p class="mt-2 text-xs text-fg-3">Load, no-fill and error figures are what the app reports (production events only). They describe delivery; they are never used to pay anyone.</p>
</div>
