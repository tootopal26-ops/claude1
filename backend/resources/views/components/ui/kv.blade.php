@props(['label'])

<div {{ $attributes->class('min-w-0') }}>
    <dt class="text-xs font-medium text-fg-3">{{ $label }}</dt>
    <dd class="mt-1 break-words text-sm text-fg">{{ $slot }}</dd>
</div>
