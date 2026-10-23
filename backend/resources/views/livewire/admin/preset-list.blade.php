<div>
    <x-ui.flash />

    <x-ui.page-header title="Permission presets" description="Reusable sets of permissions. Assign one to a staff account to decide which sections and actions it can use.">
        <x-slot:actions>
            <x-ui.button :href="portal_route('staff.index')" variant="secondary" icon="users">Staff accounts</x-ui.button>
            @if (auth('staff')->user()->hasPermission('staff.manage'))
                <x-ui.button :href="portal_route('presets.create')" icon="plus">New preset</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th>Preset</th>
                <th>Permissions</th>
                <th>Staff assigned</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @forelse ($presets as $preset)
            <tr wire:key="preset-{{ $preset->id }}">
                <td class="mm-cell-primary">
                    <span class="block max-w-[22rem] truncate" title="{{ $preset->name }}">{{ $preset->name }}</span>
                    @if ($preset->description)
                        <span class="block max-w-[28rem] truncate text-xs font-normal text-fg-3" title="{{ $preset->description }}">{{ $preset->description }}</span>
                    @endif
                </td>
                <td class="tabular-nums text-fg">{{ $preset->permissions_count }}</td>
                <td class="tabular-nums">
                    @if ($preset->staff_users_count > 0)
                        <x-ui.badge variant="brand" :dot="false">{{ $preset->staff_users_count }}</x-ui.badge>
                    @else
                        <span class="text-fg-3">0</span>
                    @endif
                </td>
                <td class="text-right">
                    <x-ui.button :href="portal_route('presets.edit', $preset)" variant="ghost" size="sm" icon="edit">Edit</x-ui.button>
                </td>
            </tr>
        @empty
            <x-ui.table-empty :colspan="4" icon="presets" title="No presets yet" description="A preset groups permissions so you can give several people the same access.">
                @if (auth('staff')->user()->hasPermission('staff.manage'))
                    <x-ui.button :href="portal_route('presets.create')" icon="plus" size="sm">New preset</x-ui.button>
                @endif
            </x-ui.table-empty>
        @endforelse
    </x-ui.table>
</div>
