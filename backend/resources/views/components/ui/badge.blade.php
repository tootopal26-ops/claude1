@props(['status' => null, 'variant' => null, 'dot' => true])

@php
    // Every status string used across the Admin/Staff UI maps to one semantic tone.
    $toneByStatus = [
        'pending' => 'warning', 'open' => 'warning', 'processing' => 'info', 'approved' => 'info',
        'upcoming' => 'info', 'ongoing' => 'brand', 'live' => 'brand',
        'paid' => 'success', 'success' => 'success', 'completed' => 'success', 'confirmed' => 'success',
        'active' => 'success', 'published' => 'success', 'resolved' => 'success', 'reviewed' => 'neutral',
        'rejected' => 'danger', 'failed' => 'danger', 'cancelled' => 'danger', 'banned' => 'danger',
        'expired' => 'danger', 'inactive' => 'neutral', 'superseded' => 'neutral',
        'high' => 'danger', 'medium' => 'warning', 'low' => 'info', 'critical' => 'danger',
    ];

    $tone = $variant ?? ($toneByStatus[strtolower((string) $status)] ?? 'neutral');

    $tones = [
        'neutral' => 'bg-surface-3 text-fg-2',
        'brand' => 'bg-brand-soft text-brand-text',
        'success' => 'bg-success-soft text-success',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
        'info' => 'bg-info-soft text-info',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', $tones[$tone] ?? $tones['neutral']]) }}>
    @if ($dot)<span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>@endif
    {{ $slot->isEmpty() ? ucfirst((string) $status) : $slot }}
</span>
