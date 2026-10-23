<div>
    <x-ui.page-header
        :title="$isAdminConsole ? 'Dashboard' : 'Your workspace'"
        :description="$isAdminConsole
            ? 'Live operational overview — only real platform data.'
            : 'Welcome back, '.$user->display_name.'. Here is what you can work on.'"
    />

    @if ($sections->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="shield" title="No sections available" description="Your account doesn't have access to any section yet. Contact an administrator to have a permission preset assigned." />
        </x-ui.card>
    @else
        @if (count($stats))
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 {{ [1 => 'xl:grid-cols-1', 2 => 'xl:grid-cols-2', 3 => 'xl:grid-cols-3', 4 => 'xl:grid-cols-4'][count($stats)] ?? 'xl:grid-cols-5' }}">
                @foreach ($stats as $stat)
                    <x-ui.stat :label="$stat['label']" :value="$stat['value']" :hint="$stat['hint']" :icon="$stat['icon']" :tone="$stat['tone']" :href="$stat['href']" />
                @endforeach
            </div>
        @endif

        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                @if ($user->hasPermission('tournaments.view'))
                    <x-ui.card title="Upcoming tournaments" description="Next scheduled starts" :padding="false">
                        <x-slot:actions>
                            <x-ui.button :href="route('admin.tournaments.index')" variant="ghost" size="sm">View all</x-ui.button>
                        </x-slot:actions>

                        <x-ui.table class="rounded-none border-0">
                            <x-slot:head>
                                <tr>
                                    <th>Tournament</th>
                                    <th>Mode</th>
                                    <th>Slots</th>
                                    <th>Starts</th>
                                </tr>
                            </x-slot:head>
                            @forelse ($upcomingTournaments as $tournament)
                                <tr>
                                    <td class="mm-cell-primary"><a href="{{ route('admin.tournaments.entries', $tournament) }}" class="block max-w-[16rem] truncate hover:text-brand-text" title="{{ $tournament->title }}">{{ $tournament->title }}</a></td>
                                    <td class="uppercase">{{ $tournament->mode }}</td>
                                    <td class="tabular-nums">{{ $tournament->joined_count }}/{{ $tournament->total_slots }}</td>
                                    <td class="whitespace-nowrap">{{ $tournament->scheduled_start_at->format('d M, H:i') }}</td>
                                </tr>
                            @empty
                                <x-ui.table-empty :colspan="4" icon="tournaments" title="No upcoming tournaments" description="Scheduled tournaments will appear here." />
                            @endforelse
                        </x-ui.table>
                    </x-ui.card>
                @endif

                <x-ui.card :title="$canSeeAllActivity ? 'Recent activity' : 'Your recent activity'" :description="$canSeeAllActivity ? 'The latest administrative actions across the platform' : 'The latest actions you performed'" :padding="false">
                    @if ($canSeeAllActivity)
                        <x-slot:actions>
                            <x-ui.button :href="route('admin.audit-logs.index')" variant="ghost" size="sm">Audit log</x-ui.button>
                        </x-slot:actions>
                    @endif

                    @if ($activity->isEmpty())
                        <x-ui.empty-state icon="audit" title="No activity yet" description="Actions will be listed here as they happen." />
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($activity as $entry)
                                <li class="flex items-start gap-3 px-5 py-3">
                                    <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-surface-2 text-fg-3"><x-icon name="audit" :size="14" /></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-fg" title="{{ $entry->action }}">{{ $entry->action }}</p>
                                        <p class="truncate text-xs text-fg-3">{{ $entry->actorLabel() }} · {{ $entry->subjectLabel() }}</p>
                                    </div>
                                    <time class="shrink-0 whitespace-nowrap text-xs text-fg-3" datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->format('d M Y, H:i:s') }}">{{ $entry->created_at->diffForHumans() }}</time>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card title="Needs attention" description="Work waiting on you">
                    @if ($attention->isEmpty())
                        <p class="flex items-center gap-2 text-sm text-fg-2"><x-icon name="check-circle" :size="16" class="text-success" /> You're all caught up.</p>
                    @else
                        <ul class="-my-1 divide-y divide-line">
                            @foreach ($attention as $item)
                                <li>
                                    <a href="{{ $item['href'] }}" class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 text-sm text-fg-2 transition-colors hover:bg-surface-2 hover:text-fg">
                                        <span class="min-w-0 truncate">{{ $item['label'] }}</span>
                                        <x-ui.badge variant="warning" :dot="false">{{ $item['count'] }}</x-ui.badge>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>

                @unless ($isAdminConsole)
                <x-ui.card title="Your sections" :padding="false">
                    <ul class="divide-y divide-line">
                        @foreach ($sections as $section)
                            <li>
                                <a href="{{ route($section['route']) }}" class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-surface-2">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-surface-2 text-fg-2"><x-icon :name="$section['icon']" :size="16" /></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-fg">{{ $section['label'] }}</span>
                                        <span class="block truncate text-xs text-fg-3">{{ $section['description'] }}</span>
                                    </span>
                                    <x-icon name="chevron-right" :size="14" class="shrink-0 text-fg-3" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
                @endunless
            </div>
        </div>
    @endif
</div>
