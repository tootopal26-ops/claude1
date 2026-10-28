<div>
    <x-ui.flash />

    <x-ui.page-header title="Redeem codes" description="Create a campaign and share its 5-character code. Each player can redeem it once, until the player limit or the expiry is reached.">
        <x-slot:actions>
            <x-ui.button :href="portal_route('redeem-codes.history')" variant="secondary" icon="clock">Redemption history</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($generated)
        <div class="mb-6 rounded-xl border border-brand/40 bg-brand-soft p-5" role="status">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-text">Code generated</p>
                    <p class="mt-2 font-mono text-4xl font-semibold tracking-[0.25em] text-fg" data-testid="generated-code">{{ $generated->code }}</p>
                    <p class="mt-2 text-sm text-fg-2">
                        {{ $generated->name }} · <span class="tabular-nums">{{ number_format($generated->reward_coins) }}</span> coins each · up to
                        <span class="tabular-nums">{{ number_format($generated->total_capacity) }}</span> players
                    </p>
                    <p class="mt-1 text-sm text-fg-2">Expires <span class="font-medium text-fg">{{ $generated->expires_at->copy()->utc()->format('d M Y, H:i') }} UTC</span> ({{ $generated->expires_at->copy()->timezone('Asia/Kolkata')->format('d M, H:i') }} IST)</p>
                </div>
                <div class="flex items-center gap-2" x-data="copyText('{{ $generated->code }}')">
                    <x-ui.button type="button" variant="primary" icon="copy" x-on:click="copy()"><span x-text="copied ? 'Copied' : 'Copy code'">Copy code</span></x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="dismissGenerated">Dismiss</x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <x-ui.card title="New campaign" description="Coins are credited to each player's deposit balance through the wallet ledger. The code is generated for you.">
        <form wire:submit="generate" novalidate class="grid gap-x-4 gap-y-5 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.input label="Campaign name" wire:model="name" required maxlength="80" placeholder="e.g. Weekend launch" hint="Internal label, never shown to players." wrapper-class="sm:col-span-2 xl:col-span-4" />

            <x-ui.input label="Player limit" type="number" wire:model.live.debounce.300ms="capacity" min="1" max="1000000" required hint="How many different players can redeem." />

            <x-ui.coin-input label="Reward per player" wire:model.live.debounce.300ms="reward" required hint="Credited once to each player." />

            <x-ui.select label="Expires after" wire:model.live="duration" required hint="From the moment you press Generate.">
                @foreach ($durations ?? \App\Livewire\Admin\RedeemCodes::DURATIONS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>

            @if ($duration === 'custom')
                <x-ui.input label="Custom duration (hours)" type="number" wire:model.live.debounce.300ms="customHours" min="1" max="8760" required hint="1 to 8,760 hours." />
            @else
                <div></div>
            @endif

            <div class="sm:col-span-2 xl:col-span-4">
                <x-ui.switch wire:model="isActive" label="Active as soon as it is generated" hint="Turn off to create it now and activate it later." />
            </div>

            <div class="sm:col-span-2 xl:col-span-4">
                <x-ui.switch wire:model="requiresAd" label="Requires a rewarded ad" hint="The player watches one complete rewarded ad after entering the code; coins are credited only when the ad network confirms it. Needs the Redeem verification placement switched on under Ads & Monetization." />
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-line bg-surface-2 px-4 py-3 text-sm sm:col-span-2 xl:col-span-4">
                <dl class="flex flex-wrap gap-x-8 gap-y-2">
                    <div>
                        <dt class="text-xs text-fg-3">Potential liability</dt>
                        <dd class="font-semibold tabular-nums text-fg">
                            @if (ctype_digit($capacity) && ctype_digit($reward) && $capacity !== '' && $reward !== '')
                                {{ number_format((int) $capacity * (int) $reward) }} coins
                            @else — @endif
                        </dd>
                        <dd class="text-xs text-fg-3">If every seat is redeemed. A planning figure — nothing is credited until players redeem.</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-fg-3">Would expire</dt>
                        <dd class="font-medium text-fg">
                            @if ($previewExpiry)
                                {{ $previewExpiry->copy()->utc()->format('d M Y, H:i') }} UTC · {{ $previewExpiry->copy()->timezone('Asia/Kolkata')->format('d M, H:i') }} IST
                            @else — @endif
                        </dd>
                    </div>
                </dl>
                <x-ui.button type="submit" size="lg" loading="generate" icon="ticket">Generate code</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mb-4 mt-8 flex flex-wrap items-end gap-3">
        <div class="w-full max-w-xs">
            <x-ui.input type="search" label="Search campaigns" wire:model.live.debounce.300ms="search" placeholder="Name or code" />
        </div>
        <div class="w-full max-w-[12rem]">
            <x-ui.select label="Status" wire:model.live="status">
                <option value="all">All current</option>
                <option value="active">Active</option>
                <option value="expired">Expired</option>
                <option value="exhausted">Fully redeemed</option>
                <option value="inactive">Inactive</option>
                <option value="archived">Archived</option>
            </x-ui.select>
        </div>
        <p class="ml-auto text-xs text-fg-3">
            Remaining liability across current campaigns: <span class="font-semibold tabular-nums text-fg">{{ number_format($totals['liability']) }}</span> coins · redeemed so far: <span class="font-semibold tabular-nums text-fg">{{ number_format($totals['redeemed']) }}</span>
        </p>
    </div>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Campaign</th>
                <th>Code</th>
                <th>Reward</th>
                <th>Redeemed</th>
                <th>Liability</th>
                <th>Expires</th>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($campaigns as $campaign)
            @php($fill = $campaign->total_capacity > 0 ? min(100, round($campaign->redeemed_count / $campaign->total_capacity * 100)) : 0)
            <tr wire:key="campaign-{{ $campaign->id }}">
                <td class="mm-cell-primary">
                    <span class="block max-w-[14rem] truncate" title="{{ $campaign->name }}">{{ $campaign->name }}</span>
                    @if ($campaign->requires_rewarded_ad)<x-ui.badge variant="info" :dot="false" class="mt-1">Rewarded ad required</x-ui.badge>@endif
                </td>
                <td>
                    <div class="flex items-center gap-2" x-data="copyText('{{ $campaign->code }}')">
                        <code class="rounded-md bg-surface-3 px-2 py-1 font-mono text-sm font-semibold tracking-widest text-fg">{{ $campaign->code }}</code>
                        <button type="button" x-on:click="copy()" class="rounded-md p-2 text-fg-3 transition-colors hover:bg-surface-2 hover:text-fg" aria-label="Copy code {{ $campaign->code }}">
                            <x-icon name="check" :size="14" x-show="copied" x-cloak />
                            <x-icon name="copy" :size="14" x-show="! copied" />
                        </button>
                        <span class="sr-only" role="status" x-text="copied ? 'Copied' : ''"></span>
                    </div>
                </td>
                <td class="whitespace-nowrap tabular-nums text-fg"><span class="inline-flex items-center gap-1.5"><x-coin-asset :size="14" />{{ number_format($campaign->reward_coins) }}</span></td>
                <td>
                    <div class="flex items-center gap-3">
                        <span class="w-20 text-xs tabular-nums text-fg">{{ number_format($campaign->redeemed_count) }} / {{ number_format($campaign->total_capacity) }}</span>
                        <div class="h-1.5 w-16 overflow-hidden rounded-full bg-surface-3" role="img" aria-label="{{ $fill }}% redeemed, {{ number_format($campaign->remaining()) }} remaining"><div class="h-full rounded-full bg-brand" style="width: {{ $fill }}%"></div></div>
                    </div>
                    <span class="mt-0.5 block text-xs text-fg-3">{{ number_format($campaign->remaining()) }} remaining</span>
                </td>
                <td class="whitespace-nowrap tabular-nums">{{ number_format($campaign->potentialLiability()) }}</td>
                <td><x-ui.moment :at="$campaign->expires_at" /></td>
                <td><x-ui.badge :status="$campaign->status() === 'exhausted' ? 'completed' : $campaign->status()">{{ $campaign->status() === 'exhausted' ? 'Fully redeemed' : ucfirst($campaign->status()) }}</x-ui.badge></td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-1">
                        @if ($campaign->isArchived())
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="unarchive({{ $campaign->id }})" loading="unarchive">Restore</x-ui.button>
                        @else
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="toggle({{ $campaign->id }})" loading="toggle">{{ $campaign->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                            <x-ui.button type="button" variant="danger-ghost" size="sm" wire:click="archive({{ $campaign->id }})" loading="archive" confirm="Archive &quot;{{ $campaign->name }}&quot; ({{ $campaign->code }})? Its code stops working and it moves out of the main list. Redemptions already made stay in the history." confirm-title="Archive campaign" confirm-label="Archive">Archive</x-ui.button>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="8" icon="ticket" :title="trim($search) !== '' || $status !== 'all' ? 'No campaigns match' : 'No campaigns yet'" description="Fill in the form above to generate your first code.">
            </x-ui.table-empty>
        @endforelse

        <x-slot:footer>{{ $campaigns->links() }}</x-slot:footer>
    </x-ui.table>
</div>
