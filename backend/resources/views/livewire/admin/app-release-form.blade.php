<div x-data="unsavedGuard" x-on:input="markDirty()" x-on:change="markDirty()" x-on:submit="clean()">
    <x-ui.page-header
        title="Upload release"
        description="Android (.apk or .aab). The file is served to players only once this release is marked current."
        :breadcrumbs="[
            ['label' => 'App Releases', 'url' => portal_route('app-releases.index')],
            ['label' => 'Upload release'],
        ]"
    />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Version" description="The version code must be higher than any previous release and is never reused.">
            <x-ui.input label="Version name" wire:model="versionName" maxlength="40" placeholder="e.g. 1.3.0" required />
            <x-ui.input label="Version code" type="number" wire:model="versionCode" min="1" required hint="A plain increasing integer, e.g. 130." />

            <x-ui.textarea label="Release notes" wire:model="releaseNotes" optional maxlength="5000" rows="4" wrapper-class="sm:col-span-2" placeholder="What changed in this build…" />

            <div class="self-end pb-1">
                <x-ui.switch wire:model="makeCurrent" label="Make this the current release" hint="Players downloading the app get this build immediately." />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Build file" description="Upload the signed release .apk or .aab produced by the EAS build (see docs/mobile-release.md). Large files can take a while depending on your connection.">
            <x-ui.input type="file" wire:model="file" aria-label="Release file" wrapper-class="sm:col-span-2" />
            <div wire:loading wire:target="file" class="text-sm text-fg-3 sm:col-span-2">Uploading…</div>
        </x-ui.form-section>

        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-between gap-3 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <p x-show="dirty" x-cloak class="text-xs text-warning" role="status">You have unsaved changes.</p>
            <span x-show="! dirty" class="hidden"></span>
            <div class="ml-auto flex items-center gap-2">
                <x-ui.button :href="portal_route('app-releases.index')" variant="ghost" data-discard>Cancel</x-ui.button>
                <x-ui.button type="submit" loading="save,file">Upload release</x-ui.button>
            </div>
        </div>
    </form>
</div>
