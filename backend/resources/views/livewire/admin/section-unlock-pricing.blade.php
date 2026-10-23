<div x-data="unsavedGuard" x-effect="dirty = $wire.hasChanges">
    <x-ui.flash />

    <x-ui.page-header title="Section unlock pricing" description="Put an app section behind a coin price. Players pay once from their wallet and keep access." />

    <x-ui.alert variant="info" class="mb-6" title="Nothing is locked until you turn paid unlocking on">
        Only sections registered by the app appear here. A section stays free for everyone until you switch paid unlocking on and save a price.
        Players who already unlocked a section keep it, whatever you change later.
    </x-ui.alert>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
        <form wire:submit="save" novalidate class="min-w-0 space-y-6">
            <x-ui.card title="Price a section" description="Whole coins. The app always shows the saved price.">
                <div class="space-y-5">
                    <x-ui.select label="Section" wire:model.live="sectionKey" required hint="Chosen from the sections the app registers.">
                        @foreach ($sections as $section)
                            <option value="{{ $section['key'] }}">{{ $section['title'] }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.switch wire:model.live="enabled" label="Paid unlocking" hint="On: players must unlock this section with coins. Off: free for everyone." />

                    <x-ui.coin-input label="Unlock price" wire:model.live.debounce.300ms="price" required hint="Charged from the deposit balance first, then withdrawable winnings." />

                    <div class="flex items-center justify-between gap-3 text-xs" role="status">
                        @if ($hasChanges)
                            <x-ui.badge variant="warning">Unsaved changes</x-ui.badge>
                        @else
                            <x-ui.badge variant="success">Saved</x-ui.badge>
                        @endif
                        <span class="text-fg-3">
                            @if ($savedLabel) Saved price: <span class="font-medium text-fg">{{ $savedLabel }}</span> @else Currently free @endif
                        </span>
                    </div>
                </div>
            </x-ui.card>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <x-ui.button type="button" variant="ghost" wire:click="cancel" :disabled="! $hasChanges">Cancel</x-ui.button>
                <x-ui.button
                    type="submit"
                    loading="save"
                    :disabled="! $hasChanges"
                    :confirm="$turnsOn ? 'Players who have not unlocked '.($selected['title'] ?? 'this section').' will be locked out of it until they pay. Turn paid unlocking on?' : null"
                    confirm-title="Turn on paid unlocking"
                    confirm-label="Turn on"
                    confirm-variant="primary"
                >Save price</x-ui.button>
            </div>
        </form>

        <div class="min-w-0 space-y-6">
            <x-ui.card title="Preview" description="How the section card looks to a player who has not unlocked it.">
                @if ($selected)
                    <div class="mm-panel flex items-center gap-4 rounded-2xl p-4" aria-label="Preview of the {{ $selected['title'] }} card">
                        <span class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-line bg-brand-soft text-brand-text"><x-icon :name="$selected['icon']" :size="22" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-fg">{{ $selected['title'] }}</p>
                            <p class="mt-0.5 text-xs text-fg-2">{{ $selected['description'] }}</p>
                        </div>
                        @if ($enabled && $draftPrice)
                            <span class="inline-flex h-10 shrink-0 items-center gap-2 rounded-full border border-brand/50 bg-brand-soft px-3.5 text-sm font-semibold text-fg">
                                <x-icon name="lock" :size="16" class="text-brand-text" /> Unlock · {{ number_format($draftPrice) }} Coins
                            </span>
                        @else
                            <span class="inline-flex h-10 shrink-0 items-center gap-2 rounded-full border border-line px-3.5 text-sm font-medium text-fg-2">
                                <x-icon name="unlock" :size="16" /> Free
                            </span>
                        @endif
                    </div>
                    <p class="mt-3 text-xs text-fg-3">
                        @if ($enabled && $draftPrice && $hasChanges)
                            This is the draft. Players see it only after you save.
                        @elseif ($enabled && $draftPrice)
                            This is what players who have not unlocked it see now.
                        @elseif ($enabled)
                            Enter a price of at least 1 coin to see the lock.
                        @else
                            Paid unlocking is off, so players see no lock on this section.
                        @endif
                    </p>
                @endif
            </x-ui.card>

            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th>Section</th>
                        <th>Status</th>
                        <th class="text-right">Price</th>
                        <th class="text-right">Unlocked by</th>
                        <th>Last changed</th>
                    </tr>
                </x-slot:head>
                @foreach ($sections as $section)
                    <tr wire:key="section-{{ $section['key'] }}">
                        <td class="mm-cell-primary">
                            <p class="font-medium text-fg">{{ $section['title'] }}</p>
                            <p class="font-mono text-xs text-fg-3">{{ $section['key'] }}</p>
                        </td>
                        <td>
                            @if ($section['saved']?->is_enabled)
                                <x-ui.badge variant="brand">Paid</x-ui.badge>
                            @else
                                <x-ui.badge variant="neutral">Free</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-right tabular-nums">{{ $section['saved'] ? number_format($section['saved']->price_coins).' coins' : '—' }}</td>
                        <td class="text-right tabular-nums">
                            {{ number_format($section['players']) }} {{ \Illuminate\Support\Str::plural('player', $section['players']) }}
                            <span class="block text-xs text-fg-3">{{ number_format($section['coins']) }} coins collected</span>
                        </td>
                        <td class="text-xs text-fg-3">
                            @if ($section['saved'])
                                <x-ui.moment :at="$section['saved']->updated_at" />
                                @if ($section['saved']->updatedBy) <span class="block">by {{ $section['saved']->updatedBy->display_name }}</span> @endif
                            @else — @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </div>
    </div>

    <x-ui.card class="mt-8" title="Change history" description="Every price or switch change, newest first.">
        @if ($history->isEmpty())
            <x-ui.empty-state title="No changes yet" description="Saving a price or turning paid unlocking on or off is recorded here." />
        @else
            <ul class="divide-y divide-line">
                @foreach ($history as $entry)
                    @php($m = $entry->metadata ?? [])
                    <li class="flex flex-wrap items-start justify-between gap-x-6 gap-y-1 py-3 text-sm" wire:key="history-{{ $entry->id }}">
                        <div class="min-w-0">
                            <p class="text-fg">
                                <span class="font-medium">{{ $sections->firstWhere('key', $m['section'] ?? '')['title'] ?? ($m['section'] ?? 'Section') }}</span>:
                                @if (($m['old_price'] ?? null) === null)
                                    price set to <span class="tabular-nums font-medium">{{ number_format($m['new_price'] ?? 0) }}</span> coins
                                @elseif (($m['old_price'] ?? null) !== ($m['new_price'] ?? null))
                                    price <span class="tabular-nums">{{ number_format($m['old_price']) }}</span> → <span class="tabular-nums font-medium">{{ number_format($m['new_price'] ?? 0) }}</span> coins
                                @else
                                    price unchanged at <span class="tabular-nums">{{ number_format($m['new_price'] ?? 0) }}</span> coins
                                @endif
                                @if (($m['old_enabled'] ?? false) !== ($m['new_enabled'] ?? false))
                                    · paid unlocking turned <span class="font-medium">{{ ($m['new_enabled'] ?? false) ? 'on' : 'off' }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-fg-3">by {{ $entry->actorLabel() }}</p>
                        </div>
                        <x-ui.moment :at="$entry->created_at" class="text-xs text-fg-3" />
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
