<div x-data="unsavedGuard" x-on:input="markDirty()" x-on:change="markDirty()" x-on:submit="clean()">
    <x-ui.page-header
        :title="$banner ? 'Edit banner' : 'New banner'"
        description="Shown in the carousel at the top of the app's leaderboard. Use a clear 16:9 picture; keep important details away from the edges."
        :breadcrumbs="[
            ['label' => 'Leaderboard banners', 'url' => portal_route('leaderboards.banners.index')],
            ['label' => $banner ? ($banner->title ?: 'Banner') : 'New banner'],
        ]"
    />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Banner details" description="The title is read aloud by screen readers; it isn't drawn on the picture.">
            <x-ui.input label="Title" wire:model="title" optional maxlength="80" placeholder="e.g. Weekend Showdown" wrapper-class="sm:col-span-2" />

            <x-ui.input label="Display order" type="number" wire:model="displayOrder" min="1" max="9999" required hint="1 shows first." />

            <div class="self-end pb-1">
                <x-ui.switch wire:model="isActive" label="Visible in the app" hint="Turn off to hide it without deleting." />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Banner image" description="JPEG, PNG or WebP, up to 5 MB and at least 640×360 once cropped to 16:9. The image is re-encoded and stored in two sizes.">
            <x-ui.image-upload
                model="image"
                :file="$image"
                :preview-url="$previewUrl"
                :current-url="$currentUrl"
                :focal-x="$focalX"
                :focal-y="$focalY"
                label="Image"
                :required="! $banner?->image_asset_id"
            />
        </x-ui.form-section>

        <div class="sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-between gap-3 border-t border-line bg-app px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <p x-show="dirty" x-cloak class="text-xs text-warning" role="status">You have unsaved changes.</p>
            <span x-show="! dirty" class="hidden"></span>
            <div class="ml-auto flex items-center gap-2">
                <x-ui.button :href="portal_route('leaderboards.banners.index')" variant="ghost" data-discard>Cancel</x-ui.button>
                <x-ui.button type="submit" loading="save,image">Save banner</x-ui.button>
            </div>
        </div>
    </form>
</div>
