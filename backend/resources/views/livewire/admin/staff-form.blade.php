<div class="mx-auto max-w-xl">
    <h1 class="mb-6 text-2xl font-bold text-violet-200">{{ $staffUser ? 'Edit staff account' : 'New staff account' }}</h1>

    <form wire:submit="save" class="space-y-4 rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <div>
            <label class="mb-1 block text-xs text-violet-300">Display name</label>
            <input type="text" wire:model="display_name" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('display_name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Username</label>
            <input type="text" wire:model="username" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('username') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="mb-1 block text-xs text-violet-300">
                    {{ $staffUser ? 'New password (leave blank to keep current)' : 'Password' }}
                </label>
                <input type="password" wire:model="password" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('password') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-violet-300">Confirm password</label>
                <input type="password" wire:model="password_confirmation" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            </div>
        </div>

        @if (! $is_super_admin || $actingStaffIsSuperAdmin)
            <div>
                <label class="mb-1 block text-xs text-violet-300">Permission preset</label>
                <select wire:model="permission_preset_id" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                    <option value="">None</option>
                    @foreach ($presets as $preset)
                        <option value="{{ $preset->id }}">{{ $preset->name }}</option>
                    @endforeach
                </select>
                @error('permission_preset_id') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        @endif

        <label class="flex items-center gap-2 text-sm text-violet-200">
            <input type="checkbox" wire:model="is_active">
            Active (an inactive account cannot log in, and any existing session is force-logged-out)
        </label>

        @if ($actingStaffIsSuperAdmin)
            <label class="flex items-center gap-2 text-sm text-amber-300">
                <input type="checkbox" wire:model="is_super_admin">
                Super admin (bypasses all permission checks)
            </label>
        @endif

        <button type="submit" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Save staff account
        </button>
    </form>
</div>
