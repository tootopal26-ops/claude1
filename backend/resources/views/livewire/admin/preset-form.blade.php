<div>
    <x-ui.page-header
        :title="$preset ? 'Edit preset' : 'New preset'"
        description="Name the preset and tick every permission that staff using it should have. Anything left unticked is denied."
        :breadcrumbs="[
            ['label' => 'Permission presets', 'url' => portal_route('presets.index')],
            ['label' => $preset ? $preset->name : 'New preset'],
        ]"
    />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Preset details" description="How this preset appears when assigning access to a staff account.">
            <x-ui.input label="Name" wire:model="name" required placeholder="e.g. Tournament manager" />

            <x-ui.textarea label="Description" wire:model="description" optional rows="2" hint="A short note on who this preset is for." />
        </x-ui.form-section>

        <section class="grid grid-cols-1 gap-4 border-t border-line py-6 lg:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] lg:gap-10">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold text-fg">Permissions</h2>
                <p class="mt-1 text-xs leading-relaxed text-fg-3">Grouped by area. Each permission also protects its pages on the server, so unticking one removes access even if someone types the URL.</p>
                @error('selectedPermissionIds') <p class="mt-3 text-xs text-danger" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="min-w-0 gap-4 xl:columns-2">
                @foreach ($permissionsByGroup as $group => $permissions)
                    <fieldset wire:key="permission-group-{{ $group }}" class="mb-4 min-w-0 break-inside-avoid mm-panel rounded-xl p-4">
                        <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-fg-3">{{ \Illuminate\Support\Str::headline($group) }}</legend>
                        <div class="mt-1 space-y-2">
                            @foreach ($permissions as $permission)
                                <label wire:key="permission-{{ $permission->id }}" class="flex cursor-pointer items-start gap-3 rounded-lg border border-line bg-surface-2 px-3 py-2.5 transition-colors hover:border-line-strong has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                                    <input type="checkbox" wire:model="selectedPermissionIds" value="{{ $permission->id }}" class="mt-0.5 size-4 shrink-0 cursor-pointer rounded border-line-strong bg-surface-2 accent-brand">
                                    <span class="min-w-0">
                                        <span class="block text-sm text-fg">{{ $permission->label }}</span>
                                        <span class="block truncate font-mono text-xs text-fg-3" title="{{ $permission->key }}">{{ $permission->key }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
        </section>

        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button :href="portal_route('presets.index')" variant="ghost">Cancel</x-ui.button>
            <x-ui.button type="submit" loading="save">Save preset</x-ui.button>
        </div>
    </form>
</div>
