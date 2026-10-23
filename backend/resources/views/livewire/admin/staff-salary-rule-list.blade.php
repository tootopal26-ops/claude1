<div>
    <x-ui.flash />

    <x-ui.page-header
        title="Salary rules"
        description="Rules decide how much a staff member earns each time they perform an action. Weekly settlements are generated from these rules."
        :breadcrumbs="[
            ['label' => 'Team Management'],
            ['label' => 'Salary rules'],
        ]"
    >
        <x-slot:actions>
            @if (auth('staff')->user()->hasPermission('salary.view'))
                <x-ui.button :href="portal_route('staff-salary.settlements.index')" variant="secondary" icon="salary">View settlements</x-ui.button>
            @endif
            @if (auth('staff')->user()->hasPermission('salary.manage'))
                <x-ui.button :href="portal_route('staff-salary.rules.create')" icon="plus">New rule</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Staff member</th>
                <th>Action type</th>
                <th>Amount per action</th>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($rules as $rule)
            <tr wire:key="rule-{{ $rule->id }}">
                <td class="mm-cell-primary"><span class="block max-w-[16rem] truncate" title="{{ $rule->staffUser->display_name }}">{{ $rule->staffUser->display_name }}</span></td>
                <td><span class="block max-w-[20rem] truncate font-mono text-xs" title="{{ $rule->action_type }}">{{ $rule->action_type }}</span></td>
                <td class="whitespace-nowrap"><span class="inline-flex items-center gap-1.5 tabular-nums text-fg"><x-coin-asset :size="16" />{{ $rule->amount_per_action }}</span></td>
                <td><x-ui.badge :status="$rule->is_active ? 'active' : 'inactive'" /></td>
                <td class="text-right">
                    <x-ui.button :href="portal_route('staff-salary.rules.edit', $rule)" variant="ghost" size="sm" icon="edit">Edit</x-ui.button>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="5" icon="salary" title="No salary rules yet" description="Add a rule to start paying a staff member for an action they perform.">
                @if (auth('staff')->user()->hasPermission('salary.manage'))
                    <x-ui.button :href="portal_route('staff-salary.rules.create')" icon="plus" size="sm">New rule</x-ui.button>
                @endif
            </x-ui.table-empty>
        @endforelse
    </x-ui.table>
</div>
