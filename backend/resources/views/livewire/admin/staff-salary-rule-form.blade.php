<div class="mx-auto max-w-xl">
    <h1 class="mb-6 text-2xl font-bold text-violet-200">{{ $rule ? 'Edit salary rule' : 'New salary rule' }}</h1>

    <form wire:submit="save" class="space-y-4 rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <div>
            <label class="mb-1 block text-xs text-violet-300">Staff member</label>
            <select wire:model="staff_user_id" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                <option value="">Select a staff member</option>
                @foreach ($staffUsers as $staffUser)
                    <option value="{{ $staffUser->id }}">{{ $staffUser->display_name }}</option>
                @endforeach
            </select>
            @error('staff_user_id') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Action type</label>
            <input type="text" wire:model="action_type" list="action-type-options"
                placeholder="e.g. tournament.credentials_released"
                class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            <datalist id="action-type-options">
                @foreach ($actionTypes as $type)
                    <option value="{{ $type }}"></option>
                @endforeach
            </datalist>
            @error('action_type') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Amount per action</label>
            <input type="number" step="0.01" min="0.01" wire:model="amount_per_action" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('amount_per_action') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-violet-200">
            <input type="checkbox" wire:model="is_active">
            Active
        </label>

        <button type="submit" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Save rule
        </button>
    </form>
</div>
