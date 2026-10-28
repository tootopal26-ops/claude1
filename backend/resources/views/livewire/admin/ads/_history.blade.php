{{-- Recorded changes to this page's settings ($history: AuditLog collection). --}}
<x-ui.card title="Change history" description="Every saved change, with who made it and the previous and new value." class="mt-6">
    @if ($history->isEmpty())
        <p class="text-sm text-fg-3">No changes recorded yet — the defaults are in use.</p>
    @else
        <ul class="divide-y divide-line text-sm">
            @foreach ($history as $log)
                @php($label = \App\Domain\Settings\PlatformSettingsService::CATALOG[$log->metadata['key']]['label'] ?? $log->metadata['key'])
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2" wire:key="hist-{{ $log->id }}">
                    <span class="min-w-0 text-fg">{{ $label }}:
                        <span class="text-fg-3">{{ is_bool($log->metadata['previous_value'] ?? null) ? (($log->metadata['previous_value']) ? 'on' : 'off') : \Illuminate\Support\Str::limit((string) json_encode($log->metadata['previous_value'] ?? null), 60) }}</span>
                        →
                        <span class="font-medium">{{ is_bool($log->metadata['new_value'] ?? null) ? (($log->metadata['new_value']) ? 'on' : 'off') : \Illuminate\Support\Str::limit((string) json_encode($log->metadata['new_value'] ?? null), 60) }}</span>
                    </span>
                    <span class="text-xs text-fg-3">{{ $log->actorLabel() }} · <time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->format('d M Y, H:i') }}</time></span>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>
