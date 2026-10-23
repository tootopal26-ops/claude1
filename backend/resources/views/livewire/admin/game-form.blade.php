<div x-data="unsavedGuard" x-on:input="markDirty()" x-on:change="markDirty()" x-on:submit="clean()">
    <x-ui.page-header
        :title="$game ? 'Edit game card' : 'New game card'"
        description="This is the card players see on the app's home screen. Pick a clear 16:9 picture and a short title."
        :breadcrumbs="[
            ['label' => 'Tournament games', 'url' => portal_route('tournament-games.index')],
            ['label' => $game ? $game->name : 'New game card'],
        ]"
    />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Card details" description="Shown on the card and used when players open the game.">
            <x-ui.input label="Title" wire:model="name" required maxlength="60" placeholder="e.g. Free Fire" wrapper-class="sm:col-span-2" />

            <x-ui.textarea label="Short description" wire:model="description" optional rows="2" maxlength="200" hint="Up to 200 characters. Shown with the game, not on the card image." wrapper-class="sm:col-span-2" />

            <x-ui.input label="Display order" type="number" wire:model="displayOrder" min="1" max="9999" required hint="1 shows first. You can also reorder from the list." />

            <div class="self-end pb-1">
                <x-ui.switch wire:model="isActive" label="Visible in the app" hint="Turn off to hide the card without deleting it." />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Card image" description="JPEG, PNG or WebP, up to 5 MB and at least 640×360 once cropped to 16:9. The image is re-encoded and stored in two sizes.">
            <x-ui.image-upload
                model="image"
                :file="$image"
                :preview-url="$previewUrl"
                :current-url="$currentUrl"
                :focal-x="$focalX"
                :focal-y="$focalY"
                label="Image"
                :required="! $game?->image_asset_id"
            />
        </x-ui.form-section>

        <div class="sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-between gap-3 border-t border-line bg-app px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <p x-show="dirty" x-cloak class="text-xs text-warning" role="status">You have unsaved changes.</p>
            <span x-show="! dirty" class="hidden"></span>
            <div class="ml-auto flex items-center gap-2">
                <x-ui.button :href="portal_route('tournament-games.index')" variant="ghost">Cancel</x-ui.button>
                <x-ui.button type="submit" loading="save,image">Save card</x-ui.button>
            </div>
        </div>
    </form>
</div>
