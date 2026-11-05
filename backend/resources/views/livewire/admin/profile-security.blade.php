<div>
    <x-ui.flash />

    <x-ui.page-header title="Password & security" description="Change the password for your own account." />

    <form wire:submit="save" novalidate class="max-w-md">
        <x-ui.form-section title="Change password">
            <x-ui.input label="Current password" type="password" wire:model="currentPassword" autocomplete="current-password" required wrapper-class="sm:col-span-2" />
            <x-ui.input label="New password" type="password" wire:model="password" autocomplete="new-password" required hint="At least 8 characters." wrapper-class="sm:col-span-2" />
            <x-ui.input label="Confirm new password" type="password" wire:model="password_confirmation" autocomplete="new-password" required wrapper-class="sm:col-span-2" />
        </x-ui.form-section>

        <div class="flex justify-end">
            <x-ui.button type="submit" loading="save">Update password</x-ui.button>
        </div>
    </form>
</div>
