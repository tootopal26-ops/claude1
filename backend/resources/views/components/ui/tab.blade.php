@props(['active' => false, 'href' => null])

@php
    $classes = 'inline-flex h-8 items-center rounded-md px-3 text-xs font-medium transition-colors '
        .($active ? 'bg-brand-soft text-brand-text' : 'text-fg-2 hover:bg-surface-2 hover:text-fg');
@endphp

@if ($href)
    <a href="{{ $href }}" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="button" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
