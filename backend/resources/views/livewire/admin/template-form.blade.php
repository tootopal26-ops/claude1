<div class="mx-auto max-w-xl">
    <h1 class="mb-6 text-2xl font-bold text-violet-200">{{ $template ? 'Edit template' : 'Create template' }}</h1>

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

        <hr class="border-violet-900/40">
        <h2 class="text-sm font-semibold text-violet-300">Recurrence</h2>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="mb-1 block text-xs text-violet-300">Frequency</label>
                <select wire:model.live="frequency" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                </select>
                @error('frequency') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
            @if ($frequency === 'weekly')
                <div>
                    <label class="mb-1 block text-xs text-violet-300">Day of week</label>
                    <select wire:model="day_of_week" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                        <option value="0">Sunday</option>
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                    </select>
                    @error('day_of_week') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="mb-1 block text-xs text-violet-300">Time of day</label>
                <input type="time" wire:model="time_of_day" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('time_of_day') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs text-violet-300">Generate how many days ahead</label>
                <input type="number" min="1" max="60" wire:model="generate_days_ahead" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                @error('generate_days_ahead') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm text-violet-200">
            <input type="checkbox" wire:model="is_active">
            Active (generates occurrences on schedule)
        </label>

        <button type="submit" class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500">
            Save template
        </button>
    </form>
</div>
