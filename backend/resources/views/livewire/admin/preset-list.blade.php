<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-violet-200">Permission presets</h1>
        <a href="{{ route('admin.presets.create') }}"
            class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
            + New preset
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Description</th>
                    <th class="px-4 py-3">Permissions</th>
                    <th class="px-4 py-3">Staff assigned</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($presets as $preset)
                    <tr>
                        <td class="px-4 py-3 font-medium text-white">{{ $preset->name }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $preset->description }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $preset->permissions_count }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $preset->staff_users_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.presets.edit', $preset) }}" class="text-violet-400 hover:text-violet-200">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-violet-400">No presets yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
