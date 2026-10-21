<div>
    <h1 class="mb-6 text-2xl font-bold text-violet-200">Players</h1>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search"
            placeholder="Search by username or mobile number…"
            class="w-full max-w-sm rounded-lg border border-violet-800/50 bg-black/30 px-3 py-2 text-sm text-white">
    </div>

    <div class="overflow-hidden rounded-2xl border border-violet-900/40">
        <table class="w-full text-left text-sm">
            <thead class="bg-white/5 text-violet-300">
                <tr>
                    <th class="px-4 py-3">Username</th>
                    <th class="px-4 py-3">Mobile</th>
                    <th class="px-4 py-3">Total balance</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Joined</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-violet-900/30">
                @forelse ($users as $user)
                    <tr>
                        <td class="px-4 py-3 font-medium text-white">{{ $user->username }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $user->mobile }}</td>
                        <td class="px-4 py-3 text-violet-300">{{ $user->wallet?->totalBalance() ?? '0.00' }}</td>
                        <td class="px-4 py-3">
                            @if ($user->is_banned)
                                <span class="text-red-400">Banned</span>
                            @else
                                <span class="text-emerald-400">Active</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-violet-300">{{ $user->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.users.show', $user) }}" class="text-violet-400 hover:text-violet-200">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-violet-400">No players found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</div>
