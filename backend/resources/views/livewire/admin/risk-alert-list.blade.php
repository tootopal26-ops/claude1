<div>
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Risk alerts</h1>

    <div class="mb-4 flex flex-wrap items-end gap-4 rounded-2xl border border-violet-900/40 bg-white/5 p-4">
        <div>
            <label class="mb-1 block text-xs text-violet-300">Type</label>
            <select wire:model.live="type" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
                <option value="">All types</option>
                @foreach ($types as $typeOption)
                    <option value="{{ $typeOption }}">{{ $typeOption }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs text-violet-300">Risk level</label>
            <select wire:model.live="riskLevel" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
                <option value="">All levels</option>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs text-violet-300">Status</label>
            <select wire:model.live="status" class="rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
                <option value="open">Open</option>
                <option value="reviewed">Reviewed</option>
                <option value="">All</option>
            </select>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Risk</th>
                    <th class="px-4 py-3">Target user</th>
                    <th class="px-4 py-3">Subject</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($alerts as $alert)
                    <tr>
                        <td class="px-4 py-3 text-violet-300">{{ $alert->created_at->format('d M, H:i:s') }}</td>
                        <td class="px-4 py-3 text-white">{{ $alert->type }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'rounded px-2 py-0.5 text-xs',
                                'bg-red-900/40 text-red-300' => $alert->risk_level === 'high',
                                'bg-amber-900/40 text-amber-300' => $alert->risk_level === 'medium',
                                'bg-violet-900/40 text-violet-300' => $alert->risk_level === 'low',
                            ])>{{ ucfirst($alert->risk_level) }}</span>
                        </td>
                        <td class="px-4 py-3 text-violet-400">{{ $alert->targetUser?->username ?? '—' }}</td>
                        <td class="px-4 py-3 text-violet-400">{{ $alert->subjectLabel() }}</td>
                        <td class="px-4 py-3">
                            @if ($alert->isOpen())
                                <span class="text-xs text-amber-300">Open</span>
                            @else
                                <span class="text-xs text-emerald-300">Reviewed by {{ $alert->reviewedByStaff?->display_name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($alert->isOpen())
                                <button type="button" wire:click="markReviewed({{ $alert->id }})" class="text-xs text-violet-400 hover:text-violet-200">Mark reviewed</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-violet-400">No risk alerts match these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $alerts->links() }}</div>
</div>
