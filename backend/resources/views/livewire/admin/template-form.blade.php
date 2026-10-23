<div>
    <x-ui.page-header
        :title="$template ? 'Edit template' : 'Create template'"
        description="A template generates tournaments automatically on a schedule, using the settings below."
        :breadcrumbs="[
            ['label' => 'Recurring Templates', 'url' => portal_route('templates.index')],
            ['label' => $template ? 'Edit' : 'Create'],
        ]"
    />

    <form wire:submit="save" novalidate>
        <x-ui.form-section title="Basic details" description="What players will see on every tournament this template creates.">
            <x-ui.select label="Game" wire:model="game_id" required>
                <option value="">Select a game</option>
                @foreach ($games as $game)
                    <option value="{{ $game->id }}">{{ $game->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.input label="Title" wire:model="title" required placeholder="e.g. Weekly Solo Showdown" />

            <x-ui.textarea label="Description" wire:model="description" optional rows="3" wrapperClass="sm:col-span-2" hint="Shown to players on the tournament page." />
        </x-ui.form-section>

        <x-ui.form-section title="Tournament configuration" description="Format, capacity and entry cost.">
            <x-ui.select label="Mode" wire:model="mode" required>
                <option value="solo">Solo</option>
                <option value="duo">Duo</option>
                <option value="squad">Squad</option>
            </x-ui.select>

            <x-ui.input label="Total slots (teams)" type="number" min="1" wire:model="total_slots" required hint="How many teams can join." />

            <x-ui.input label="Entry fee (coins)" type="number" step="0.01" min="0" wire:model="entry_fee" required hint="Enter 0 for a free tournament." />

            <x-ui.input label="Minimum teams" type="number" min="1" wire:model="min_players" optional placeholder="No minimum" hint="If fewer teams have joined when the match is due to start, the tournament is cancelled and entry fees are refunded." />
        </x-ui.form-section>

        <x-ui.form-section title="Rewards" description="Prize settings copied onto each generated tournament.">
            <x-ui.input label="Prize pool (coins)" type="number" step="0.01" min="0" wire:model="prize_pool" required hint="The most that can be paid out when results are published." />

            <x-ui.input label="Per-kill reward (coins)" type="number" step="0.01" min="0" wire:model="per_kill_reward" required hint="Reward per kill shown for tournaments generated from this template." />
        </x-ui.form-section>

        <x-ui.form-section title="Recurrence" description="When tournaments are created and how far ahead.">
            <x-ui.select label="Frequency" wire:model.live="frequency" required>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
            </x-ui.select>

            @if ($frequency === 'weekly')
                <x-ui.select label="Day of week" wire:model="day_of_week" required>
                    <option value="0">Sunday</option>
                    <option value="1">Monday</option>
                    <option value="2">Tuesday</option>
                    <option value="3">Wednesday</option>
                    <option value="4">Thursday</option>
                    <option value="5">Friday</option>
                    <option value="6">Saturday</option>
                </x-ui.select>
            @endif

            <x-ui.input label="Time of day" type="time" wire:model="time_of_day" required hint="24-hour start time." />

            <x-ui.input label="Generate how many days ahead" type="number" min="1" max="60" wire:model="generate_days_ahead" required hint="Between 1 and 60 days." />

            <div class="sm:col-span-2">
                <x-ui.switch wire:model="is_active" label="Active" hint="Paused templates stop generating new tournaments; existing ones are unaffected." />
            </div>
        </x-ui.form-section>

        <div class="sticky bottom-0 z-10 -mx-4 mt-2 flex items-center justify-end gap-2 border-t border-line bg-app px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
            <x-ui.button :href="portal_route('templates.index')" variant="ghost">Cancel</x-ui.button>
            <x-ui.button type="submit" loading="save">Save template</x-ui.button>
        </div>
    </form>
</div>
