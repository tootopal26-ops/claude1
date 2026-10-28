@props(['label', 'value', 'hint' => null, 'icon' => null, 'href' => null, 'tone' => 'neutral', 'coin' => false])

@php
    $iconTone = [
        'neutral' => 'bg-surface-3 text-fg-2',
        'brand' => 'bg-brand-soft text-brand-text',
        'success' => 'bg-success-soft text-success',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
        'info' => 'bg-info-soft text-info',
    ][$tone] ?? 'bg-surface-3 text-fg-2';
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class(['flex min-w-0 flex-col gap-2 rounded-xl border border-line bg-surface p-4 shadow-card', 'transition-colors hover:border-line-strong hover:bg-surface-2' => $href]) }}>
    <div class="flex items-center justify-between gap-3">
        <p class="truncate text-xs font-medium text-fg-3">{{ $label }}</p>
        @if ($icon)
            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $iconTone }}"><x-icon :name="$icon" :size="16" /></span>
        @endif
    </div>
    <p class="flex items-center gap-2 text-2xl font-semibold tabular-nums tracking-tight text-fg">@if ($coin)<x-coin-asset :size="22" />@endif{{ $value }}</p>
    @if ($hint)<p class="text-xs leading-snug text-fg-3">{{ $hint }}</p>@endif
</{{ $tag }}>
