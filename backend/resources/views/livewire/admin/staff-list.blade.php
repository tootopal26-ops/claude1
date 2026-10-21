<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-violet-200">Staff accounts</h1>
        <a href="{{ route('admin.staff.create') }}"
            class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
            + New staff account
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Username</th>
                    <th class="px-4 py-3">Preset</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($staffMembers as $staff)
                    <tr>
                        <td class="px-4 py-3 font-medium text-white">{{ $staff->display_name }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $staff->username }}</td>
                        <td class="px-4 py-3 text-violet-300">
                            @if ($staff->is_super_admin)
                                <span class="text-amber-400">Super Admin</span>
                            @else
                                {{ $staff->permissionPreset->name ?? '—' }}
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if ($staff->is_active)
                                <span class="text-emerald-400">Active</span>
                            @else
                                <span class="text-red-400">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.staff.edit', $staff) }}" class="text-violet-400 hover:text-violet-200">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-violet-400">No staff accounts yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
