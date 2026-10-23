@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'icon' => null,
    'iconOnly' => false,
    'type' => 'button',
    'loading' => null,
    'confirm' => null,
    'confirmTitle' => null,
    'confirmLabel' => null,
    'confirmVariant' => null,
])

@php
    $variants = [
        'primary' => 'bg-brand text-on-brand hover:bg-brand-hover active:bg-brand-pressed',
        'secondary' => 'border border-line bg-surface-2 text-fg hover:bg-surface-3',
        'outline' => 'border border-line-strong text-fg hover:bg-surface-2',
        'ghost' => 'text-fg-2 hover:bg-surface-2 hover:text-fg',
        'danger' => 'bg-danger-solid text-on-danger hover:bg-danger-solid-hover',
        'danger-ghost' => 'text-danger hover:bg-danger-soft',
    ];

    $sizes = $iconOnly
        ? ['sm' => 'size-8', 'md' => 'size-9', 'lg' => 'size-11']
        : ['sm' => 'h-8 px-3 text-xs', 'md' => 'h-9 px-4 text-sm', 'lg' => 'h-11 px-5 text-sm'];

    $classes = 'inline-flex shrink-0 select-none items-center justify-center gap-2 whitespace-nowrap rounded-lg font-medium transition-colors disabled:pointer-events-none disabled:opacity-50 '
        .($variants[$variant] ?? $variants['primary']).' '
        .($sizes[$size] ?? $sizes['md']);

    $iconSize = $size === 'sm' ? 14 : 16;

    $confirmAttributes = $confirm ? [
        'data-confirm' => $confirm,
        'data-confirm-title' => $confirmTitle,
        'data-confirm-label' => $confirmLabel,
        'data-confirm-variant' => $confirmVariant ?? (str_starts_with($variant, 'danger') ? 'danger' : 'primary'),
    ] : [];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes])->merge($confirmAttributes) }}>
        @if ($icon)<x-icon :name="$icon" :size="$iconSize" />@endif
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->merge(['class' => $classes])->merge($confirmAttributes) }}
        @if ($loading) wire:loading.attr="disabled" wire:target="{{ $loading }}" @endif
    >
        @if ($loading)
            <span class="mm-spinner" wire:loading wire:target="{{ $loading }}" aria-hidden="true"></span>
            @if ($icon)<x-icon :name="$icon" :size="$iconSize" wire:loading.remove wire:target="{{ $loading }}" />@endif
        @elseif ($icon)
            <x-icon :name="$icon" :size="$iconSize" />
        @endif
        {{ $slot }}
    </button>
@endif
