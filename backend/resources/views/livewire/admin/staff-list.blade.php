<div>
    <x-ui.flash />

    <x-ui.page-header title="Staff accounts" description="Everyone who can sign in to the Admin Console or Staff Portal, and the access each account has.">
        <x-slot:actions>
            <x-ui.button :href="portal_route('presets.index')" variant="secondary" icon="presets">Permission presets</x-ui.button>
            @if (auth('staff')->user()->hasPermission('staff.manage'))
                <x-ui.button :href="portal_route('staff.create')" icon="plus">New staff account</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Access</th>
                <th>Status</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($staffMembers as $staff)
            <tr wire:key="staff-{{ $staff->id }}">
                <td class="mm-cell-primary">
                    <div class="flex items-center gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-text" aria-hidden="true">{{ mb_strtoupper(mb_substr($staff->display_name, 0, 1)) }}</span>
                        <span class="block max-w-[16rem] truncate" title="{{ $staff->display_name }}">{{ $staff->display_name }}</span>
                        @if ($staff->id === auth('staff')->id())
                            <x-ui.badge variant="neutral" :dot="false">You</x-ui.badge>
                        @endif
                    </div>
                </td>
                <td><span class="block max-w-[14rem] truncate font-mono text-xs" title="{{ $staff->username }}">{{ $staff->username }}</span></td>
                <td>
                    @if ($staff->is_super_admin)
                        <x-ui.badge variant="brand" :dot="false"><x-icon name="shield" :size="12" /> Super admin</x-ui.badge>
                    @elseif ($staff->permissionPreset)
                        <span class="block max-w-[14rem] truncate text-fg" title="{{ $staff->permissionPreset->name }}">{{ $staff->permissionPreset->name }}</span>
                    @else
                        <span class="text-fg-3">No preset</span>
                    @endif
                </td>
                <td><x-ui.badge :status="$staff->is_active ? 'active' : 'inactive'" /></td>
                <td class="text-right">
                    <x-ui.button :href="portal_route('staff.edit', $staff)" variant="ghost" size="sm" icon="edit">Edit</x-ui.button>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="5" icon="user" title="No staff accounts yet" description="Create an account and assign a permission preset to give someone access.">
                @if (auth('staff')->user()->hasPermission('staff.manage'))
                    <x-ui.button :href="portal_route('staff.create')" icon="plus" size="sm">New staff account</x-ui.button>
                @endif
            </x-ui.table-empty>
        @endforelse
    </x-ui.table>
</div>
