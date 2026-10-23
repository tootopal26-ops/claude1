<div x-data="unsavedGuard" x-effect="dirty = $wire.hasChanges">
    <x-ui.flash />

    <x-ui.page-header title="Leaderboard management" description="Rankings, prize rules, payout settlement and the banners shown at the top of the app's leaderboard." />

    <x-leaderboard.subnav current="prizes" />

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <x-ui.tabs label="Leaderboard period">
            @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $value => $label)
                <x-ui.tab :href="portal_route('leaderboards.prizes', ['type' => $value])" :active="$type === $value">{{ $label }}</x-ui.tab>
            @endforeach
        </x-ui.tabs>

        <div class="flex items-center gap-2 text-xs" role="status">
            @if ($hasChanges)
                <x-ui.badge variant="warning">Unsaved changes</x-ui.badge>
            @else
                <x-ui.badge variant="success">Saved</x-ui.badge>
            @endif
        </div>
    </div>

    <x-ui.alert variant="info" class="mb-6" title="These prizes are for {{ $type }} leaderboards only">
        Daily, weekly and monthly prizes are separate. Changes apply to the next {{ $type }} period that finalizes — saving never pays anyone,
        and a period that has already finalized keeps the prizes it closed with.
        @if ($lastFinalized)
            Last finalized: {{ $lastFinalized->period_start->timezone('Asia/Kolkata')->format('d M Y') }} (IST).
        @endif
    </x-ui.alert>

    <form wire:submit="save" novalidate class="grid gap-6 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
        <div class="min-w-0 space-y-6">
            <x-ui.card title="{{ ucfirst($type) }} prizes" description="Whole coins only. Leave a rank at 0 for no prize.">
                <div class="space-y-5">
                    <x-ui.switch wire:model.live="enabled" label="Pay prizes for {{ $type }} leaderboards" hint="Off: the period still finalizes, but nothing is paid." />

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
                        <x-ui.coin-input label="Rank 1" wire:model.live.debounce.400ms="first" required />
                        <x-ui.coin-input label="Rank 2" wire:model.live.debounce.400ms="second" required />
                        <x-ui.coin-input label="Rank 3" wire:model.live.debounce.400ms="third" required />
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Ranks 4–100" description="One amount for all 97 ranks, applied in one step.">
                <div class="space-y-4">
                    <p class="text-sm text-fg-2">
                        @if ($restIsUniform)
                            Ranks 4–100 currently pay <span class="font-semibold tabular-nums text-fg">{{ number_format((int) $rest) }}</span> coins each.
                        @else
                            Ranks 4–100 currently have different amounts. Applying a single amount replaces them all.
                        @endif
                    </p>

                    <x-ui.coin-input label="Amount for each of ranks 4–100" wire:model="bulkAmount" x-on:keydown.enter.prevent="document.getElementById('apply-bulk').click()" />

                    <x-ui.button
                        id="apply-bulk"
                        type="button"
                        variant="secondary"
                        wire:click="applyBulk"
                        loading="applyBulk"
                        confirm="Set every rank from 4 to 100 to the amount you entered? You can still review the preview and cancel before saving."
                        confirm-title="Apply to ranks 4–100"
                        confirm-label="Apply to all 97 ranks"
                    >Apply to ranks 4–100</x-ui.button>
                </div>
            </x-ui.card>

            <details class="mm-panel rounded-xl p-5 text-sm text-fg-2">
                <summary class="cursor-pointer text-sm font-semibold text-fg">How ranks and prizes are decided</summary>
                <ul class="mt-3 list-disc space-y-2 pl-5 leading-relaxed">
                    <li><span class="text-fg">Ranking</span> is by total prize winnings in the period, then total kills, then player ID — so every player has a different rank and a tie never splits or duplicates a prize.</li>
                    <li><span class="text-fg">Missing ranks:</span> if fewer players ranked than ranks have prizes, the unfilled ranks pay nothing and their prizes are not passed down.</li>
                    <li><span class="text-fg">Corrections:</span> until a period closes, a corrected or withdrawn result changes the ranking and the prizes follow it. Once the period finalizes, its ranking and prizes are locked.</li>
                    <li><span class="text-fg">Banned accounts</span> are not paid. Their prize is withheld (shown on Settlement) and is not passed to the next rank; it can be paid by a retry if the ban is lifted.</li>
                    <li><span class="text-fg">Periods</span> follow IST: a day, Monday–Sunday, or a calendar month. Prizes are credited as winnings, once per player per period.</li>
                </ul>
            </details>
        </div>

        <x-ui.card title="Prize preview" description="Ranks 1–100 with the amounts above. Nothing here is paid until a {{ $type }} period finalizes." :padding="false" class="min-w-0">
            <x-slot:actions>
                <span class="text-xs text-fg-3">If all 100 ranks are filled: <span class="inline-flex items-center gap-1 font-semibold tabular-nums text-fg"><x-coin-asset :size="13" />{{ number_format($totalIfFull) }}</span> coins</span>
            </x-slot:actions>

            <div class="max-h-[34rem] overflow-y-auto" tabindex="0" role="region" aria-label="Prize for each rank, 1 to {{ $maxRank }}">
                <table class="mm-table">
                    <thead class="sticky top-0 z-[1]">
                        <tr><th class="w-24">Rank</th><th class="text-right">Prize (coins)</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($table as $rank => $coins)
                            <tr wire:key="preview-{{ $rank }}">
                                <td class="tabular-nums {{ $rank <= 3 ? 'font-semibold text-fg' : '' }}">#{{ $rank }}</td>
                                <td class="text-right tabular-nums {{ $coins > 0 ? 'text-fg' : 'text-fg-3' }}">
                                    @if ($coins > 0)
                                        <span class="inline-flex items-center justify-end gap-1.5"><x-coin-asset :size="14" />{{ number_format($coins) }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <div class="mm-glass-bar sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-2 border-t border-line px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 xl:col-span-2">
            <x-ui.button type="button" variant="ghost" wire:click="cancel" :disabled="! $hasChanges">Cancel</x-ui.button>
            <x-ui.button type="submit" loading="save" :disabled="! $hasChanges">Save {{ $type }} prizes</x-ui.button>
        </div>
    </form>
</div>
