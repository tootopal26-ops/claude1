@php
    $audienceOptions = [
        'all' => ['label' => 'All active users', 'hint' => 'Every player whose account is not banned.'],
        'selected_users' => ['label' => 'Selected users', 'hint' => 'Pick specific players by username.'],
        'tournament_participants' => ['label' => 'Tournament participants', 'hint' => 'Players with a confirmed entry in one tournament.'],
    ];

    $selectedTournament = $audienceType === 'tournament_participants' && $tournamentId
        ? $tournaments->firstWhere('id', (int) $tournamentId)
        : null;

    $selectedCount = $this->selectedUsers->count();

    $audienceSummary = match ($audienceType) {
        'all' => 'all active players',
        'selected_users' => $selectedCount === 0 ? 'the players you select' : $selectedCount.' selected '.($selectedCount === 1 ? 'player' : 'players'),
        'tournament_participants' => $selectedTournament ? 'the confirmed participants of "'.$selectedTournament->title.'"' : 'the participants of the tournament you choose',
        default => 'the chosen audience',
    };
@endphp

<div>
    <x-ui.page-header
        title="Send notification"
        description="Compose a message and choose who receives it. It is delivered to real players immediately."
        :breadcrumbs="[
            ['label' => 'Notifications', 'url' => route('admin.notifications.index')],
            ['label' => 'Send'],
        ]"
    />

    @if ($errors->any())
        <x-ui.alert variant="danger" title="Some fields need attention" class="mb-6">Check the highlighted fields below, then send again.</x-ui.alert>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="min-w-0 space-y-6 xl:col-span-2">
            <x-ui.card title="Message" description="What the player sees in their notification center and as a push notification.">
                <div class="space-y-5">
                    <x-ui.input label="Title" wire:model="title" required placeholder="e.g. Tonight's tournament starts at 8 PM" hint="Keep it short — up to 255 characters." />
                    <x-ui.textarea label="Message" wire:model="body" required rows="4" hint="Up to 1,000 characters. Never include room credentials or payout amounts in a broadcast." />
                </div>
            </x-ui.card>

            <x-ui.card title="Audience" description="Who receives this notification.">
                <fieldset class="space-y-3">
                    <legend class="sr-only">Audience</legend>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        @foreach ($audienceOptions as $value => $option)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-line-strong bg-surface-2 p-3 transition-colors hover:border-fg-3/60 has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                                <input type="radio" wire:model.live="audienceType" value="{{ $value }}" class="mt-0.5 size-4 shrink-0 cursor-pointer accent-brand">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-fg">{{ $option['label'] }}</span>
                                    <span class="mt-0.5 block text-xs text-fg-3">{{ $option['hint'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('audienceType') <p class="text-xs text-danger" role="alert">{{ $message }}</p> @enderror
                </fieldset>

                @if ($audienceType === 'selected_users')
                    <div class="mt-5 space-y-3 border-t border-line pt-5">
                        <x-ui.input label="Find players" wire:model.live.debounce.300ms="userSearch" placeholder="Start typing a username…" autocomplete="off" />

                        @if ($this->searchResults->isNotEmpty())
                            <ul class="max-h-48 divide-y divide-line overflow-y-auto rounded-lg border border-line-strong bg-surface-2" aria-label="Matching players">
                                @foreach ($this->searchResults as $user)
                                    <li wire:key="result-{{ $user->id }}">
                                        <button type="button" wire:click="addUser({{ $user->id }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-fg-2 transition-colors hover:bg-surface-3 hover:text-fg">
                                            <span class="truncate">{{ $user->username }}</span>
                                            <x-icon name="plus" :size="14" class="shrink-0 text-fg-3" />
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($this->selectedUsers->isNotEmpty())
                            <div>
                                <p class="mb-2 text-xs font-medium text-fg-3">Selected ({{ $selectedCount }})</p>
                                <ul class="flex flex-wrap gap-2">
                                    @foreach ($this->selectedUsers as $user)
                                        <li wire:key="selected-{{ $user->id }}" class="inline-flex items-center gap-1 rounded-full bg-brand-soft py-1 pl-3 pr-1 text-xs font-medium text-brand-text">
                                            <span class="max-w-[10rem] truncate">{{ $user->username }}</span>
                                            <button type="button" wire:click="removeUser({{ $user->id }})" class="inline-flex size-5 items-center justify-center rounded-full hover:bg-surface-3" aria-label="Remove {{ $user->username }}">
                                                <x-icon name="close" :size="12" />
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @error('selectedUserIds') <p class="flex items-start gap-1.5 text-xs text-danger" role="alert"><x-icon name="x-circle" :size="14" class="mt-px shrink-0" /><span>{{ $message }}</span></p> @enderror
                    </div>
                @endif

                @if ($audienceType === 'tournament_participants')
                    <div class="mt-5 border-t border-line pt-5">
                        <x-ui.select label="Tournament" wire:model="tournamentId" required hint="Only players with a confirmed entry receive it.">
                            <option value="">Select a tournament</option>
                            @foreach ($tournaments as $tournament)
                                <option value="{{ $tournament->id }}">{{ $tournament->title }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                @endif
            </x-ui.card>
        </div>

        <div class="min-w-0 space-y-6 xl:sticky xl:top-20 xl:self-start">
            <x-ui.card title="Preview" description="Roughly how it appears on a player's device.">
                <div class="rounded-xl border border-line-strong bg-surface-2 p-3.5">
                    <div class="flex items-center gap-2 text-xs text-fg-3">
                        <x-logo-asset :height="16" />
                        <span class="font-medium text-fg-2">Mini Max</span>
                        <span aria-hidden="true">·</span>
                        <span>now</span>
                    </div>
                    <p @class(['mt-2 break-words text-sm font-semibold', 'text-fg' => $title !== '', 'text-fg-3' => $title === ''])>{{ $title !== '' ? $title : 'Notification title' }}</p>
                    <p @class(['mt-0.5 break-words text-sm', 'text-fg-2' => $body !== '', 'text-fg-3' => $body === ''])>{{ $body !== '' ? $body : 'Your message will appear here.' }}</p>
                </div>
            </x-ui.card>

            <x-ui.card title="Recipients">
                <p class="flex items-start gap-2 text-sm text-fg-2">
                    <x-icon name="users" :size="16" class="mt-0.5 shrink-0 text-fg-3" />
                    <span>This will be delivered to <strong class="font-semibold text-fg">{{ $audienceSummary }}</strong>.</span>
                </p>

                <x-ui.alert variant="warning" class="mt-4">Notifications go out immediately and can't be recalled.</x-ui.alert>

                <x-ui.button
                    wire:click="send"
                    class="mt-4 w-full"
                    icon="notifications"
                    loading="send"
                    :confirm="'Send this notification to '.$audienceSummary.'? Players receive it immediately and it can\'t be recalled.'"
                    confirm-title="Send notification"
                    confirm-label="Send now"
                >
                    <span wire:loading.remove wire:target="send">Send notification</span>
                    <span wire:loading wire:target="send">Sending…</span>
                </x-ui.button>
            </x-ui.card>
        </div>
    </div>
</div>
