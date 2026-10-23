@props(['text'])

<span {{ $attributes->class('group/tip relative inline-flex') }}>
    {{ $slot }}
    <span role="tooltip" class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-surface-3 px-2 py-1 text-xs text-fg shadow-float group-hover/tip:block group-focus-within/tip:block">{{ $text }}</span>
</span>
