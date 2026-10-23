<div>
    <x-ui.page-header
        :title="$staffUser ? 'Edit staff account' : 'New staff account'"
        :description="$staffUser ? 'Update how this person signs in and what they can access.' : 'Create a sign-in for a team member and choose what they can access.'"
        :breadcrumbs="[
            ['label' => 'Staff', 'url' => portal_route('staff.index')],
            ['label' => $staffUser ? $staffUser->display_name : 'New account'],
        ]"
    />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Account details" description="How this person appears in the panel and in the audit log.">
            <x-ui.input label="Display name" wire:model="display_name" required autocomplete="name" />

            <x-ui.input label="Username" wire:model="username" required autocomplete="off" autocapitalize="none" spellcheck="false" hint="Letters, numbers, dashes and underscores only. Used to sign in." />
        </x-ui.form-section>

        <x-ui.form-section title="Security" description="Passwords are stored hashed and are never shown again.">
            <x-ui.input
                type="password"
                :label="$staffUser ? 'New password' : 'Password'"
                wire:model="password"
                :required="! $staffUser"
                :optional="(bool) $staffUser"
                autocomplete="new-password"
                :hint="$staffUser ? 'Leave blank to keep the current password. At least 8 characters.' : 'At least 8 characters.'"
            />

            <x-ui.input type="password" label="Confirm password" wire:model="password_confirmation" autocomplete="new-password" :required="! $staffUser" :optional="(bool) $staffUser" />
        </x-ui.form-section>

        <x-ui.form-section title="Access" description="Which sections this account can open, and whether it can sign in at all.">
            @if (! $is_super_admin || $actingStaffIsSuperAdmin)
                <x-ui.select label="Permission preset" wire:model="permission_preset_id" wrapperClass="sm:col-span-2" hint="The preset decides which sections and actions this account can use.">
                    <option value="">None</option>
                    @foreach ($presets as $preset)
                        <option value="{{ $preset->id }}">{{ $preset->name }}</option>
                    @endforeach
                </x-ui.select>
            @else
                <div class="sm:col-span-2">
                    <x-ui.alert variant="info" title="Super admin account">Only a super admin can change the access of another super admin.</x-ui.alert>
                </div>
            @endif

            <div class="sm:col-span-2">
                <x-ui.switch wire:model="is_active" label="Active" hint="An inactive account cannot sign in, and any session it already has is signed out." />
            </div>

            @if ($actingStaffIsSuperAdmin)
                <div class="rounded-lg border border-warning/40 bg-warning-soft p-4 sm:col-span-2">
                    <div class="flex gap-3">
                        <x-icon name="alert" :size="18" class="mt-0.5 shrink-0 text-warning" />
                        <div class="min-w-0 flex-1 space-y-3">
                            <div>
                                <p class="text-sm font-semibold text-warning">Super admin access</p>
                                <p class="mt-1 text-sm text-fg-2">Bypasses every permission check. This account could open and change everything, including other staff accounts, presets and settings. Grant it only to people who need full control.</p>
                            </div>
                            <x-ui.switch wire:model="is_super_admin" label="Grant super admin access" />
                        </div>
                    </div>
                </div>
            @endif
        </x-ui.form-section>

        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button :href="portal_route('staff.index')" variant="ghost">Cancel</x-ui.button>
            <x-ui.button
                type="submit"
                loading="save"
                :confirm="$staffUser
                    ? 'Save changes to this staff account? Changing the permission preset, active status, super-admin access or password signs the account out of any active sessions.'
                    : 'Create this staff account with the access you selected?'"
                :confirm-title="$staffUser ? 'Save staff account' : 'Create staff account'"
                :confirm-label="$staffUser ? 'Save changes' : 'Create account'"
            >{{ $staffUser ? 'Save staff account' : 'Create staff account' }}</x-ui.button>
        </div>
    </form>
</div>
