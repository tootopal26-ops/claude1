<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-violet-200">Recurring tournament templates</h1>
        <a href="{{ route('admin.templates.create') }}"
            class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
            + New template
        </a>
    </div>

    @if ($generateStatus)
        <div class="mb-4 rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ $generateStatus }}</div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Title</th>
                    <th class="px-4 py-3">Game</th>
                    <th class="px-4 py-3">Recurrence</th>
                    <th class="px-4 py-3">Generated</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($templates as $template)
                    <tr>
                        <td class="px-4 py-3 font-medium text-white">{{ $template->title }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $template->game->name }}</td>
                        <td class="px-4 py-3 text-violet-300">
                            @if ($template->frequency === 'weekly')
                                Weekly · {{ $template->dayOfWeekLabel() }} {{ \Illuminate\Support\Carbon::parse($template->time_of_day)->format('H:i') }}
                            @else
                                Daily · {{ \Illuminate\Support\Carbon::parse($template->time_of_day)->format('H:i') }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-violet-300">{{ $template->generated_tournaments_count }}</td>
                        <td class="px-4 py-3">
                            @if ($template->is_active)
                                <span class="text-emerald-400">Active</span>
                            @else
                                <span class="text-violet-500">Paused</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" wire:click="generateNow({{ $template->id }})" class="mr-3 text-violet-400 hover:text-violet-200">Generate now</button>
                            <a href="{{ route('admin.templates.edit', $template) }}" class="text-violet-400 hover:text-violet-200">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-violet-400">No recurring templates yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
