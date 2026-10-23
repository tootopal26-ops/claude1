<div>
    <x-ui.flash />

    <x-ui.page-header
        title="Results"
        :description="$tournament->title.' · '.ucfirst($tournament->mode).'. Enter each team\'s rank, kills and prize, then save.'"
        :breadcrumbs="[
            ['label' => 'Tournaments', 'url' => portal_route('tournaments.index')],
            ['label' => $tournament->title, 'url' => portal_route('tournaments.entries', $tournament)],
            ['label' => 'Results'],
        ]"
    >
        <x-slot:actions>
            <x-ui.badge :status="$tournament->status" class="px-3 py-1 text-sm" />
            <x-ui.button :href="portal_route('tournaments.entries', $tournament)" variant="outline" icon="arrow-left">Back to entries</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Overview">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-3">
            <x-ui.kv label="Prize pool">
                <span class="inline-flex items-center gap-1.5 text-lg font-semibold tabular-nums"><x-coin-asset :size="18" />{{ $tournament->prize_pool }}</span>
                <span class="block text-xs text-fg-3">The most that can be awarded</span>
            </x-ui.kv>
            <x-ui.kv label="Teams to score">
                <span class="text-lg font-semibold tabular-nums">{{ count($rows) }}</span>
                <span class="block text-xs text-fg-3">Confirmed entries</span>
            </x-ui.kv>
            <x-ui.kv label="Tournament status">
                <x-ui.badge :status="$tournament->status" />
                <span class="mt-1 block text-xs text-fg-3">{{ $tournament->status === 'completed' ? 'Results are published; saving revises them' : 'Saving publishes the results' }}</span>
            </x-ui.kv>
        </dl>
    </x-ui.card>

    @if ($submitError)
        <x-ui.alert variant="danger" title="Results weren't saved" class="mt-6">{{ $submitError }}</x-ui.alert>
    @endif

    <form wire:submit="submit" class="mt-6" novalidate>
        <x-ui.table>
            <x-slot:head>
                <tr class="max-sm:hidden">
                    <th>Account</th>
                    <th>Rank</th>
                    <th>Kills</th>
                    <th>Prize awarded (coins)</th>
                </tr>
            </x-slot:head>

            @forelse ($rows as $index => $row)
                <tr wire:key="result-row-{{ $row['tournament_entry_id'] }}" class="max-sm:grid max-sm:grid-cols-3 max-sm:gap-3 max-sm:px-4 max-sm:py-4">
                    <td class="mm-cell-primary max-sm:col-span-3 max-sm:p-0!"><span class="block max-w-[16rem] truncate" title="{{ $row['username'] }}">{{ $row['username'] }}</span></td>
                    <td class="align-top max-sm:p-0!"><span class="mb-1 block text-xs font-medium text-fg-3 sm:hidden" aria-hidden="true">Rank</span><x-ui.input type="number" min="1" wire:model="rows.{{ $index }}.rank" :aria-label="'Rank for '.$row['username']" class="sm:w-24" /></td>
                    <td class="align-top max-sm:p-0!"><span class="mb-1 block text-xs font-medium text-fg-3 sm:hidden" aria-hidden="true">Kills</span><x-ui.input type="number" min="0" wire:model="rows.{{ $index }}.kills" :aria-label="'Kills for '.$row['username']" class="sm:w-24" /></td>
                    <td class="align-top max-sm:p-0!"><span class="mb-1 block text-xs font-medium text-fg-3 sm:hidden" aria-hidden="true">Prize</span><x-ui.input type="number" step="0.01" min="0" wire:model="rows.{{ $index }}.prize_awarded" :aria-label="'Prize awarded to '.$row['username']" class="sm:w-36" /></td>
                </tr>
            @empty
                <x-ui.table-empty :colspan="4" icon="users" title="No confirmed entries to score" description="Results can be entered once teams have joined this tournament." />
            @endforelse
        </x-ui.table>

        @if (count($rows) > 0)
            <div class="sticky bottom-0 z-10 -mx-4 mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-line bg-app px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
                <p class="text-xs text-fg-3">Prize changes are applied to player wallets immediately.</p>
                <x-ui.button type="submit" icon="check" loading="submit" confirm="Save these results? Prize changes affect player wallets immediately." confirm-title="Save results" confirm-label="Save results">Save results</x-ui.button>
            </div>
        @endif
    </form>

    @if (auth('staff')->user()->hasPermission('results.upload_attachments'))
        <x-ui.card title="Result evidence" description="Screenshots or recordings that back up the published results." class="mt-8">
            @if ($evidenceError)
                <x-ui.alert variant="danger" title="Upload failed" class="mb-4">{{ $evidenceError }}</x-ui.alert>
            @endif

            <form wire:submit="uploadEvidence" class="flex flex-col gap-3 sm:flex-row sm:items-start" novalidate>
                <div class="min-w-0 flex-1">
                    <x-ui.input type="file" wire:model="evidenceFile" aria-label="Evidence file" />
                </div>
                <x-ui.button type="submit" icon="upload" loading="evidenceFile,uploadEvidence">Upload</x-ui.button>
            </form>

            <ul class="mt-5 divide-y divide-line rounded-lg border border-line">
                @forelse ($this->evidence as $asset)
                    <li wire:key="evidence-{{ $asset->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <a href="{{ portal_route('storage-assets.download', $asset) }}" class="block max-w-[22rem] truncate text-sm font-medium text-brand-text hover:underline" title="{{ $asset->original_filename }}">{{ $asset->original_filename }}</a>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-fg-3">
                                <span>{{ number_format($asset->size_bytes / 1024, 0) }} KB</span>
                                <span aria-hidden="true">&middot;</span>
                                <span>{{ $asset->created_at->diffForHumans() }}</span>
                                @if ($asset->isRetained())
                                    <x-ui.badge variant="warning" :dot="false">Retained: {{ $asset->retained_reason }}</x-ui.badge>
                                @endif
                            </p>
                        </div>
                        <x-ui.button type="button" variant="danger-ghost" size="sm" icon="trash" wire:click="deleteEvidence({{ $asset->id }})" loading="deleteEvidence" confirm="Delete this evidence file permanently?" confirm-title="Delete evidence" confirm-label="Delete">Delete</x-ui.button>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-fg-3">No evidence uploaded yet.</li>
                @endforelse
            </ul>
        </x-ui.card>
    @endif
</div>
