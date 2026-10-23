@props(['label' => 'Sections'])

<div {{ $attributes->class('inline-flex max-w-full flex-wrap items-center gap-1 rounded-lg border border-line bg-surface p-1') }} role="tablist" aria-label="{{ $label }}">
    {{ $slot }}
</div>
