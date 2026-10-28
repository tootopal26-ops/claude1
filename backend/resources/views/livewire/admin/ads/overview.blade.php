@php
    $usd = fn ($value) => '$'.number_format((float) $value, 2);
    $usdFine = fn ($value) => '$'.number_format((float) $value, 4);
    $num = fn ($value) => number_format((int) $value);
    $pct = fn ($value) => $value === null ? '—' : $value.'%';
@endphp
<div>
    <x-ui.page-header title="Ads & Monetization" description="Live figures from the database. App-reported estimates, network-verified rewards and provider-reported revenue are kept separate — only finalized provider revenue is ever shared with players." />

    @include('livewire.admin.ads._nav')

    @if (! $masterEnabled)
        <x-ui.alert variant="warning" title="Ads are switched off" class="mb-4">No ad requests or rewarded opportunities are offered while "All ads" is off (Ads Earning page). Past records stay as they are.</x-ui.alert>
    @endif
    @if ($testMode)
        <x-ui.alert variant="info" title="Test mode is on" class="mb-4">Events received now are recorded as test-only and excluded from every figure below (they are counted separately). Turn test mode off for production.</x-ui.alert>
    @endif

    <x-ui.card class="mb-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.select label="Period" wire:model.live="range">
                @foreach (\App\Domain\Ads\AdsAnalytics::RANGES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            @if ($range === 'custom')
                <x-ui.input label="From" type="date" wire:model.live="from" />
                <x-ui.input label="To" type="date" wire:model.live="to" />
            @endif
            <x-ui.select label="Placement" wire:model.live="placement">
                <option value="">All placements</option>
                @foreach ($placements as $option)
                    <option value="{{ $option->key }}">{{ $option->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Network (as the SDK reports it)" wire:model.live="network">
                <option value="">All networks</option>
                @foreach ($networks as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Reward type" wire:model.live="purpose">
                <option value="">All reward types</option>
                @foreach (\App\Domain\Ads\AdsAnalytics::REWARD_TYPES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input label="Player (ID or username)" wire:model.live.debounce.400ms="player" placeholder="All players" :hint="$player !== '' ? ($playerUser ? 'Showing '.$playerUser->username.' (#'.$playerUser->id.')' : 'No player matches — nothing is shown.') : null" />
        </div>
        <p class="mt-3 text-xs text-fg-3">{{ $start->format('d M Y') }} – {{ $end->format('d M Y') }} ({{ config('minimax.ads_earning_timezone') }})</p>
    </x-ui.card>

    <h2 class="mb-2 text-sm font-semibold text-fg">Revenue</h2>
    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Today — estimated" :value="$usdFine($stats['estimate']['today'])" hint="App-reported (SDK) estimate. Not settled." icon="clock" />
        <x-ui.stat label="Yesterday — estimated" :value="$usdFine($stats['estimate']['yesterday'])" hint="App-reported (SDK) estimate. Not settled." icon="clock" />
        <x-ui.stat label="This week — estimated" :value="$usdFine($stats['estimate']['this_week'])" hint="App-reported (SDK) estimate. Not settled." icon="calendar" />
        <x-ui.stat label="This month — estimated" :value="$usdFine($stats['estimate']['this_month'])" hint="App-reported (SDK) estimate. Not settled." icon="calendar" />
        <x-ui.stat label="Selected period — estimated" :value="$usdFine($stats['estimate']['range'])" hint="SDK estimate for the filters above." />
        <x-ui.stat label="Provider revenue — finalized" :value="$usd($stats['provider_revenue_finalized'])" hint="Entered from the LevelPlay dashboard and marked finalized. The only revenue that settles." tone="success" icon="check-circle" />
        <x-ui.stat label="Provider revenue — pending" :value="$usd($stats['provider_revenue_estimated'])" hint="Entered but not finalized yet." tone="warning" />
        <x-ui.stat label="Business share / players' pool" :value="$usd($stats['business_share_usd']).' / '.$usd($stats['user_pool_usd'])" :hint="'From finalized revenue at '.config('minimax.ads_earning_business_share').'% / '.config('minimax.ads_earning_user_share').'%. A policy split, not a guarantee.'" />
    </div>

    <h2 class="mb-2 text-sm font-semibold text-fg">Ad delivery</h2>
    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Requests" :value="$num($stats['requests'])" hint="Ads the app asked for." />
        <x-ui.stat label="Loads" :value="$num($stats['loads'])" hint="Ads that loaded (not yet shown)." />
        <x-ui.stat label="Eligible impressions" :value="$num($stats['impressions'])" hint="Ads actually displayed (production only)." icon="eye" />
        <x-ui.stat label="Rewarded impressions" :value="$num($stats['rewarded_impressions'])" hint="Displayed on rewarded placements." />
        <x-ui.stat label="Failed / no-fill requests" :value="$num($stats['failed_requests'])" :hint="'No-fill rate '.$pct($stats['no_fill_rate']).' of requests.'" tone="warning" icon="alert" />
        <x-ui.stat label="Rewarded ads completed" :value="$num($stats['rewarded_completed'])" hint="Verified by the ad network's signed callback." tone="success" icon="check-circle" />
        <x-ui.stat label="Rewarded completion rate" :value="$pct($stats['completion_rate'])" :hint="$num($stats['claims_opened']).' rewarded ads started in this period.'" />
        <x-ui.stat label="Unique viewers" :value="$num($stats['unique_viewers'])" hint="Players with at least one impression." icon="users" />
        <x-ui.stat label="SDK errors" :value="$num($stats['sdk_errors'])" hint="Initialization, load and display failures the app reported." tone="danger" />
    </div>

    <h2 class="mb-2 text-sm font-semibold text-fg">Players and rewards</h2>
    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Ads Earning switched on" :value="$num($stats['opted_in_users'])" :hint="'Players with Ads Earning on (now) — '.$pct($stats['opt_in_rate']).' of eligible players.'" />
        <x-ui.stat label="Eligible players" :value="$num($stats['eligible_users'])" :hint="'Successful deposits totalling '.config('minimax.ads_earning_min_deposit').' or more.'" tone="success" />
        <x-ui.stat label="Not yet eligible" :value="$num($stats['not_eligible_users'])" hint="Below the deposit requirement." />
        <x-ui.stat label="Units awaiting calculation" :value="$num($stats['units_pending_calculation'])" hint="Verified Ads Earning ads not in a calculated settlement yet." tone="info" />
        <x-ui.stat label="Pending settlement" :value="number_format((float) $stats['coins_pending_settlement'], 2)" coin hint="Calculated, not credited yet." tone="warning" />
        <x-ui.stat label="Credited by settlements" :value="number_format((float) $stats['coins_credited_settlements'], 2)" coin hint="All time, confirmed by the wallet ledger." tone="success" />
        <x-ui.stat label="All ad reward coins credited" :value="number_format((float) $stats['reward_coins_credited'], 2)" coin hint="Ledger reward entries in this period (tickets excluded — they aren't coins)." />
        <x-ui.stat label="Failed or disputed records" :value="$num($stats['failed_allocations'] + $stats['disputed_revenue_reports'])" :hint="$stats['failed_allocations'].' failed credits · '.$stats['disputed_revenue_reports'].' disputed revenue reports'" tone="danger" />
    </div>

    @if ($stats['test_events'] > 0 || $stats['simulated_completed'] > 0)
        <x-ui.alert variant="info" class="mb-6">Excluded as test-only: {{ $num($stats['test_events']) }} test SDK events and {{ $num($stats['simulated_completed']) }} simulated (QA) rewarded completions.</x-ui.alert>
    @endif

    <x-ui.card title="Daily trend" description="Displayed impressions and network-verified rewarded completions per day." class="mb-6">
        @if (collect($daily)->sum(fn ($day) => $day['impressions'] + $day['completed']) === 0)
            <x-ui.empty-state icon="leaderboard" title="No production ad activity in this period" description="Figures appear here once the app reports real (non-test) ads." />
        @else
            <div class="flex h-48 items-end gap-1 overflow-x-auto" role="img" aria-label="Daily impressions and verified completions">
                @foreach ($daily as $day)
                    <div class="flex h-full min-w-6 flex-1 flex-col items-center justify-end gap-1" title="{{ $day['day'] }}: {{ $day['impressions'] }} impressions, {{ $day['completed'] }} verified completions">
                        <div class="flex h-full w-full items-end justify-center gap-0.5">
                            <span class="w-2 rounded-t bg-brand" style="height: {{ round(100 * $day['impressions'] / $dailyMax) }}%"></span>
                            <span class="w-2 rounded-t bg-success" style="height: {{ round(100 * $day['completed'] / $dailyMax) }}%"></span>
                        </div>
                        <span class="text-[10px] text-fg-3">{{ \Carbon\Carbon::parse($day['day'])->format('d/m') }}</span>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 flex gap-4 text-xs text-fg-3"><span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-brand"></span>Impressions</span><span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-success"></span>Verified completions</span></p>
        @endif
    </x-ui.card>

    <h2 class="mb-2 text-sm font-semibold text-fg">By placement</h2>
    <x-ui.table label="Ad placements" class="mb-6">
        <x-slot:head>
            <tr><th>Placement</th><th>Format</th><th class="text-right">Requests</th><th class="text-right">Loads</th><th class="text-right">Fill rate</th><th class="text-right">No-fill</th><th class="text-right">Errors</th><th class="text-right">Impressions</th><th class="text-right">Verified</th><th class="text-right">Est. revenue</th></tr>
        </x-slot:head>
        @foreach ($byPlacement as $row)
            <tr wire:key="pl-{{ $row['key'] }}">
                <td class="mm-cell-primary">{{ $row['name'] }} @unless ($row['enabled'])<x-ui.badge variant="neutral" :dot="false">Off</x-ui.badge>@endunless</td>
                <td>{{ ucfirst(str_replace('_', '-', $row['format'])) }}</td>
                <td class="text-right tabular-nums">{{ $num($row['requests']) }}</td>
                <td class="text-right tabular-nums">{{ $num($row['loads']) }}</td>
                <td class="text-right tabular-nums">{{ $pct($row['fill_rate']) }}</td>
                <td class="text-right tabular-nums">{{ $num($row['no_fill']) }}</td>
                <td class="text-right tabular-nums">{{ $num($row['errors']) }}</td>
                <td class="text-right tabular-nums">{{ $num($row['impressions']) }}</td>
                <td class="text-right tabular-nums">{{ $row['format'] === 'rewarded' ? $num($row['verified_completions']) : '—' }}</td>
                <td class="text-right tabular-nums">{{ $usdFine($row['estimated_revenue_usd']) }}</td>
            </tr>
        @endforeach
    </x-ui.table>

    <h2 class="mb-2 text-sm font-semibold text-fg">By network</h2>
    <x-ui.table label="Ad networks">
        <x-slot:head><tr><th>Network (SDK-reported)</th><th class="text-right">Impressions</th><th class="text-right">Est. revenue</th></tr></x-slot:head>
        @forelse ($byNetwork as $row)
            <tr wire:key="nw-{{ $row['network'] }}"><td class="mm-cell-primary">{{ $row['network'] }}</td><td class="text-right tabular-nums">{{ $num($row['impressions']) }}</td><td class="text-right tabular-nums">{{ $usdFine($row['estimated_revenue_usd']) }}</td></tr>
        @empty
            <x-ui.table-empty :colspan="3" icon="leaderboard" title="No impressions in this period" description="Networks appear once the app reports real ads." />
        @endforelse
    </x-ui.table>
</div>
