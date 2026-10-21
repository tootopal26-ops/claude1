<div class="mx-auto max-w-2xl">
    <h1 class="mb-6 text-2xl font-bold text-violet-200">{{ $preset ? 'Edit preset' : 'New preset' }}</h1>

    <form wire:submit="save" class="space-y-6 rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <div>
            <label class="mb-1 block text-xs text-violet-300">Name</label>
            <input type="text" wire:model="name" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('name') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Description</label>
            <textarea wire:model="description" rows="2" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white"></textarea>
        </div>

        <div>
            <label class="mb-2 block text-xs text-violet-300">Permissions</label>
            <div class="space-y-4">
                @foreach ($permissionsByGroup as $group => $permissions)
                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-violet-500">{{ $group }}</p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($permissions as $permission)
                                <label class="flex items-center gap-2 rounded-lg border border-violet-900/30 bg-black/20 px-3 py-2 text-sm text-violet-200">
                                    <input type="checkbox" wire:model="selectedPermissionIds" value="{{ $permission->id }}">
                                    {{ $permission->label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <button type="submit" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Save preset
        </button>
    </form>
</div>
