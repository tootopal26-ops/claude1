<div>
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-violet-200">Legal documents</h1>
        @if (auth('staff')->user()->hasPermission('legal.manage'))
            <a href="{{ route('admin.legal.create') }}" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">Publish new version</a>
        @endif
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-emerald-900/40 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    @forelse ($documentsByType as $type => $documents)
        <div class="mb-8">
            <h2 class="mb-3 text-lg font-semibold text-violet-200">{{ $type === 'privacy_policy' ? 'Privacy Policy' : 'Terms & Conditions' }}</h2>
            <div class="overflow-hidden rounded-2xl border border-violet-900/40">
                <table class="w-full text-left text-sm">
                    <thead class="bg-white/5 text-violet-300">
                        <tr>
                            <th class="px-4 py-3">Version</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">Published by</th>
                            <th class="px-4 py-3">Published at</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-violet-900/30">
                        @foreach ($documents as $document)
                            <tr>
                                <td class="px-4 py-3 text-white">v{{ $document->version }}</td>
                                <td class="px-4 py-3 text-violet-300">{{ $document->title }}</td>
                                <td class="px-4 py-3 text-violet-400">{{ $document->publishedByStaff->display_name }}</td>
                                <td class="px-4 py-3 text-violet-400">{{ $document->published_at->format('d M Y H:i') }}</td>
                                <td class="px-4 py-3">
                                    @if ($document->is_current)
                                        <span class="rounded bg-emerald-900/40 px-2 py-0.5 text-xs text-emerald-300">Current</span>
                                    @else
                                        <span class="text-xs text-violet-500">Superseded</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <p class="text-violet-400">No legal documents published yet.</p>
    @endforelse
</div>
