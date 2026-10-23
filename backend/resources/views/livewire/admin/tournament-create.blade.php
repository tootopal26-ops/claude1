<div>
    <x-ui.page-header
        title="Create tournament"
        description="Schedule a one-off tournament. Players can join as soon as it is created."
        :breadcrumbs="[
            ['label' => 'Tournaments', 'url' => portal_route('tournaments.index')],
            ['label' => 'Create'],
        ]"
    />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Basic details" description="What players will see on the tournament page.">
            <x-ui.select label="Game" wire:model="game_id" required>
                <option value="">Select a game</option>
                @foreach ($games as $game)
                    <option value="{{ $game->id }}">{{ $game->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input label="Title" wire:model="title" required placeholder="e.g. Weekend Solo Showdown" />

            <x-ui.textarea label="Description" wire:model="description" optional rows="3" wrapperClass="sm:col-span-2" hint="Shown to players on the tournament page." />
        </x-ui.form-section>

        <x-ui.form-section title="Format and capacity" description="How the match is played and how many teams can enter.">
            <x-ui.select label="Mode" wire:model="mode" required>
                <option value="solo">Solo</option>
                <option value="duo">Duo</option>
                <option value="squad">Squad</option>
            </x-ui.select>

            <x-ui.input label="Total slots (teams)" type="number" min="1" wire:model="total_slots" required hint="How many teams can join (1–200)." />

            <x-ui.input label="Minimum teams" type="number" min="1" wire:model="min_players" optional placeholder="No minimum" wrapperClass="sm:col-span-2" hint="Cannot exceed the total slots. If fewer teams have joined when the match is due to start, the tournament is cancelled and entry fees are refunded." />
        </x-ui.form-section>

        <x-ui.form-section title="Entry and rewards" description="What it costs to join and what can be paid out.">
            <x-ui.input label="Entry fee (coins)" type="number" step="0.01" min="0" wire:model="entry_fee" required hint="Enter 0 for a free tournament." />

            <x-ui.input label="Prize pool (coins)" type="number" step="0.01" min="0" wire:model="prize_pool" required hint="The most that can be paid out when results are published." />

            <x-ui.input label="Per-kill reward (coins)" type="number" step="0.01" min="0" wire:model="per_kill_reward" required hint="Reward per kill shown for this tournament." />
        </x-ui.form-section>

        <x-ui.form-section title="Schedule" description="When the match starts and how long joining stays open.">
            <x-ui.input label="Scheduled start" type="datetime-local" wire:model="scheduled_start_at" required hint="Must be in the future." />

            <div class="sm:col-span-2">
                <x-ui.alert variant="info" title="How joining works">
                    Joining stays open until {{ config('minimax.tournament_join_window_minutes') }} minutes after the scheduled start, or until credentials are released, whichever comes first.
                    @if (config('minimax.tournament_min_players_grace_minutes'))
                        If a minimum is set and it isn't met {{ config('minimax.tournament_min_players_grace_minutes') }} minutes after start, the tournament auto-cancels and any entry fees are refunded.
                    @endif
                </x-ui.alert>
            </div>
        </x-ui.form-section>

        <div class="sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line bg-app px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button :href="portal_route('tournaments.index')" variant="ghost">Cancel</x-ui.button>
            <x-ui.button type="submit" loading="save">Create tournament</x-ui.button>
        </div>
    </form>
</div>
