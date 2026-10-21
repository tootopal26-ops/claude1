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
                <label class="mb-1 block text-xs text-violet-300">Mode</label>
                <select wire:model="mode" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                    <option value="solo">Solo</option>
                    <option value="duo">Duo</option>
                    <option value="squad">Squad</option>
                </select>
                @error('mode') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-violet-300">Total slots (teams)</label>
                <input type="number" min="1" wire:model="total_slots" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('total_slots') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="mb-1 block text-xs text-violet-300">Entry fee (coins, 0 = free)</label>
                <input type="number" step="0.01" min="0" wire:model="entry_fee" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('entry_fee') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-violet-300">Min teams required (optional)</label>
                <input type="number" min="1" wire:model="min_players" placeholder="No minimum" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('min_players') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="mb-1 block text-xs text-violet-300">Per-kill reward (coins)</label>
                <input type="number" step="0.01" min="0" wire:model="per_kill_reward" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('per_kill_reward') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-violet-300">Prize pool (coins)</label>
                <input type="number" step="0.01" min="0" wire:model="prize_pool" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('prize_pool') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Scheduled start</label>
            <input type="datetime-local" wire:model="scheduled_start_at" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('scheduled_start_at') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <p class="text-xs text-violet-500">
            Joining stays open until {{ config('minimax.tournament_join_window_minutes') }} minutes after the scheduled start, or until credentials are released, whichever comes first.
            @if (config('minimax.tournament_min_players_grace_minutes'))
                If a minimum is set and it isn't met {{ config('minimax.tournament_min_players_grace_minutes') }} minutes after start, the tournament auto-cancels and any entry fees are refunded.
            @endif
        </p>

        <button type="submit" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Create tournament
        </button>
    </form>
</div>
