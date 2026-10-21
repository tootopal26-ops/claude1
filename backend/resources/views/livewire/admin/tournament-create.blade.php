<div class="mx-auto max-w-xl">
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Create tournament</h1>

    <form wire:submit="save" class="space-y-4 rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <div>
            <label class="mb-1 block text-xs text-violet-300">Game</label>
            <select wire:model="game_id" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                <option value="">Select a game</option>
                @foreach ($games as $game)
                    <option value="{{ $game->id }}">{{ $game->name }}</option>
                @endforeach
            </select>
            @error('game_id') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Title</label>
            <input type="text" wire:model="title" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('title') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Description</label>
            <textarea wire:model="description" rows="3" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white"></textarea>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="mb-1 block text-xs text-violet-300">Entry fee (coins, 0 = free)</label>
                <input type="number" step="0.01" min="0" wire:model="entry_fee" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('entry_fee') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-violet-300">Total slots</label>
                <input type="number" min="1" wire:model="total_slots" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('total_slots') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Scheduled start</label>
            <input type="datetime-local" wire:model="scheduled_start_at" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('scheduled_start_at') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <p class="text-xs text-violet-500">Mode is fixed to Solo for this phase; Duo/Squad ship in a later phase.</p>

        <button type="submit" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Create tournament
        </button>
    </form>
</div>
