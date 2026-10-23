<div>
    <x-ui.flash />

    <x-ui.page-header title="Legal documents" description="Terms and policy documents players must accept. Every version stays on file for audit and dispute purposes.">
        <x-slot:actions>
            @if (auth('staff')->user()->hasPermission('legal.manage'))
                <x-ui.button :href="route('admin.legal.create')" icon="plus">Publish new version</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @forelse ($documentsByType as $type => $documents)
        @php($current = $documents->firstWhere('is_current', true))
        <x-ui.card
            :title="$type === 'privacy_policy' ? 'Privacy Policy' : 'Terms & Conditions'"
            :description="$current ? 'Current version: v'.$current->version.', published '.$current->published_at->format('d M Y') : 'No current version'"
            :padding="false"
            class="mb-6"
            wire:key="legal-{{ $type }}"
        >
            <x-ui.table class="rounded-none border-0">
                <x-slot:head>
                    <tr>
                        <th>Version</th>
                        <th>Title</th>
                        <th>Published by</th>
                        <th>Published at</th>
                        <th>Status</th>
                    </tr>
                </x-slot:head>

                @foreach ($documents as $document)
                    <tr wire:key="legal-doc-{{ $document->id }}">
                        <td class="mm-cell-primary tabular-nums">v{{ $document->version }}</td>
                        <td><span class="block max-w-[18rem] truncate" title="{{ $document->title }}">{{ $document->title }}</span></td>
                        <td class="whitespace-nowrap">{{ $document->publishedByStaff->display_name }}</td>
                        <td class="whitespace-nowrap">
                            <time datetime="{{ $document->published_at->toIso8601String() }}" title="{{ $document->published_at->format('d M Y, H:i:s') }}">{{ $document->published_at->format('d M Y H:i') }}</time>
                        </td>
                        <td>
                            @if ($document->is_current)
                                <x-ui.badge variant="success">Current</x-ui.badge>
                            @else
                                <x-ui.badge variant="neutral">Superseded</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @empty
        <x-ui.card>
            <x-ui.empty-state icon="shield" title="No legal documents published yet" description="Publish the Terms & Conditions and Privacy Policy that players accept when they register.">
                @if (auth('staff')->user()->hasPermission('legal.manage'))
                    <x-ui.button :href="route('admin.legal.create')" icon="plus" size="sm">Publish new version</x-ui.button>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @endforelse
</div>
