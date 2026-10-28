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

    <x-ui.table label="Ad units">
        <x-slot:head><tr><th>Name</th><th>Format</th><th>Environment</th><th>LevelPlay ID</th><th>Used by</th><th>Status</th><th class="text-right">Actions</th></tr></x-slot:head>
        @forelse ($units as $unit)
            <tr wire:key="unit-{{ $unit->id }}">
                <td class="mm-cell-primary">{{ $unit->name }}</td>
                <td>{{ ucfirst($unit->format) }}</td>
                <td><x-ui.badge :variant="$unit->environment === 'production' ? 'brand' : 'neutral'" :dot="false">{{ ucfirst($unit->environment) }}</x-ui.badge></td>
                <td class="font-mono text-xs">{{ $unit->levelplay_ad_unit_id }}</td>
                <td>{{ $unit->placements_count }} {{ \Illuminate\Support\Str::plural('placement', $unit->placements_count) }}</td>
                <td><x-ui.badge :status="$unit->is_active ? 'active' : 'inactive'" /></td>
                <td class="text-right">
                    <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $unit->id }})">Edit</x-ui.button>
                    <x-ui.button size="sm" variant="ghost" wire:click="toggle({{ $unit->id }})" loading="toggle">{{ $unit->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="7" icon="presets" title="No ad units yet" description="Add the LevelPlay ad unit IDs from your LevelPlay dashboard." />
        @endforelse
    </x-ui.table>
</div>
