<div class="mx-auto max-w-2xl">
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Send notification</h1>

    <div class="space-y-6 rounded-2xl border border-violet-900/40 bg-white/5 p-6">
        <div>
            <label class="mb-1 block text-xs text-violet-300">Title</label>
            <input type="text" wire:model="title" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
            @error('title') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-violet-300">Message</label>
            <textarea wire:model="body" rows="3" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white"></textarea>
            @error('body') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="mb-2 block text-xs text-violet-300">Audience</label>
            <div class="flex gap-4 text-sm text-violet-200">
                <label class="flex items-center gap-2">
                    <input type="radio" wire:model.live="audienceType" value="all"> All active users
                </label>
                <label class="flex items-center gap-2">
                    <input type="radio" wire:model.live="audienceType" value="selected_users"> Selected users
                </label>
                <label class="flex items-center gap-2">
                    <input type="radio" wire:model.live="audienceType" value="tournament_participants"> Tournament participants
                </label>
            </div>
            @error('audienceType') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        @if ($audienceType === 'selected_users')
            <div>
                <label class="mb-1 block text-xs text-violet-300">Search by username</label>
                <input type="text" wire:model.live.debounce.300ms="userSearch"
                    class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white"
                    placeholder="Start typing a username...">

                @if ($this->searchResults->isNotEmpty())
                    <div class="mt-2 max-h-40 overflow-y-auto rounded-lg border border-violet-900/40">
                        @foreach ($this->searchResults as $user)
                            <button type="button" wire:click="addUser({{ $user->id }})"
                                class="block w-full px-3 py-2 text-left text-sm text-violet-200 hover:bg-violet-900/30">
                                {{ $user->username }}
                            </button>
                        @endforeach
                    </div>
                @endif

                @if ($this->selectedUsers->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($this->selectedUsers as $user)
                            <span class="flex items-center gap-1 rounded-full bg-violet-800/40 px-3 py-1 text-xs text-violet-100">
                                {{ $user->username }}
                                <button type="button" wire:click="removeUser({{ $user->id }})" class="text-violet-300 hover:text-white">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif
                @error('selectedUserIds') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        @endif

        @if ($audienceType === 'tournament_participants')
            <div>
                <label class="mb-1 block text-xs text-violet-300">Tournament</label>
                <select wire:model="tournamentId" class="w-full rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-white">
                    <option value="">Select a tournament</option>
                    @foreach ($tournaments as $tournament)
                        <option value="{{ $tournament->id }}">{{ $tournament->title }}</option>
                    @endforeach
                </select>
                @error('tournamentId') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        @endif

        @if ($title || $body)
            <div class="rounded-xl border border-violet-800/40 bg-black/20 p-4">
                <p class="mb-1 text-[10px] uppercase tracking-wide text-violet-500">Preview</p>
                <p class="font-semibold text-white">{{ $title ?: 'Notification title' }}</p>
                <p class="text-sm text-violet-300">{{ $body ?: 'Notification message will appear here.' }}</p>
            </div>
        @endif

        <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send"
            class="w-full rounded-lg bg-violet-600 py-2 font-semibold text-white hover:bg-violet-500 disabled:opacity-50">
            <span wire:loading.remove wire:target="send">Send notification</span>
            <span wire:loading wire:target="send">Sending…</span>
        </button>
    </div>
</div>
