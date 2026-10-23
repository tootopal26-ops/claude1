<div>
    <x-ui.page-header title="Risk alerts" description="Activity the platform flagged for a human decision. Mark an alert reviewed once it has been looked at." />

    <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
        <x-ui.tabs label="Alert status">
            <x-ui.tab :active="$status === 'open'" wire:click="$set('status', 'open')">Open</x-ui.tab>
            <x-ui.tab :active="$status === 'reviewed'" wire:click="$set('status', 'reviewed')">Reviewed</x-ui.tab>
            <x-ui.tab :active="$status === ''" wire:click="$set('status', '')">All</x-ui.tab>
        </x-ui.tabs>

        <div class="grid w-full grid-cols-1 gap-4 sm:w-auto sm:grid-cols-2">
            <x-ui.select label="Type" wire:model.live="type" wrapperClass="sm:w-64">
                <option value="">All types</option>
                @foreach ($types as $typeOption)
                    <option value="{{ $typeOption }}">{{ \Illuminate\Support\Str::headline($typeOption) }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Risk level" wire:model.live="riskLevel" wrapperClass="sm:w-44">
                <option value="">All levels</option>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
            </x-ui.select>
        </div>
    </div>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>When</th>
                <th>Alert</th>
                <th>Risk</th>
                <th>Target user</th>
                <th>Subject</th>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($alerts as $alert)
            <tr wire:key="alert-{{ $alert->id }}">
                <td class="whitespace-nowrap">
                    <time datetime="{{ $alert->created_at->toIso8601String() }}" title="{{ $alert->created_at->format('d M Y, H:i:s') }}">{{ $alert->created_at->format('d M, H:i:s') }}</time>
                </td>
                <td class="mm-cell-primary">
                    <span class="block max-w-[18rem] truncate" title="{{ $alert->type }}">{{ \Illuminate\Support\Str::headline($alert->type) }}</span>
                </td>
                <td><x-ui.badge :status="$alert->risk_level">{{ ucfirst($alert->risk_level) }}</x-ui.badge></td>
                <td class="whitespace-nowrap">
                    @if ($alert->targetUser && auth('staff')->user()->hasPermission('users.view'))
                        <a href="{{ portal_route('users.show', $alert->targetUser) }}" class="text-brand-text hover:underline">{{ $alert->targetUser->username }}</a>
                    @else
                        {{ $alert->targetUser?->username ?? '—' }}
                    @endif
                </td>
                <td class="whitespace-nowrap">{{ $alert->subjectLabel() }}</td>
                <td>
                    @if ($alert->isOpen())
                        <x-ui.badge variant="warning">Open</x-ui.badge>
                    @else
                        <x-ui.badge variant="neutral">Reviewed</x-ui.badge>
                        @if ($alert->reviewedByStaff)
                            <span class="mt-1 block max-w-[10rem] truncate text-xs text-fg-3" title="Reviewed by {{ $alert->reviewedByStaff->display_name }}">by {{ $alert->reviewedByStaff->display_name }}</span>
                        @endif
                    @endif
                </td>
                <td class="text-right">
                    @if ($alert->isOpen())
                        <x-ui.button
                            wire:click="markReviewed({{ $alert->id }})"
                            variant="outline"
                            size="sm"
                            icon="check"
                            loading="markReviewed"
                            confirm="Mark this alert as reviewed? It leaves the open list and the review is recorded in the audit log."
                            confirm-title="Mark alert reviewed"
                            confirm-label="Mark reviewed"
                        >Mark reviewed</x-ui.button>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.table-empty
                :colspan="7"
                icon="shield"
                :title="$status === 'open' ? 'No open risk alerts' : 'No risk alerts match these filters'"
                :description="$status === 'open' ? 'Nothing is waiting for review right now.' : 'Try a different status, type or risk level.'"
            />
        @endforelse

        <x-slot:footer>{{ $alerts->links() }}</x-slot:footer>
    </x-ui.table>
</div>
