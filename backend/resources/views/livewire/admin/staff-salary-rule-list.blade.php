<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-violet-200">Staff salary rules</h1>
        <a href="{{ route('admin.staff-salary.rules.create') }}"
            class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">
            + New rule
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Staff member</th>
                    <th class="px-4 py-3">Action type</th>
                    <th class="px-4 py-3">Amount per action</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($rules as $rule)
                    <tr>
                        <td class="px-4 py-3 text-white">{{ $rule->staffUser->display_name }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $rule->action_type }}</td>
                        <td class="px-4 py-3 text-emerald-400">{{ $rule->amount_per_action }}</td>
                        <td class="px-4 py-3">
                            @if ($rule->is_active)
                                <span class="text-emerald-400">Active</span>
                            @else
                                <span class="text-violet-500">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.staff-salary.rules.edit', $rule) }}" class="text-violet-400 hover:text-violet-200">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-violet-400">No salary rules yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
