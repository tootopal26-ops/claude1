@props(['label' => null, 'hint' => null])

@php
    $id = $attributes->get('id') ?? 'r-'.\Illuminate\Support\Str::random(6);
@endphp

<label for="{{ $id }}" class="flex cursor-pointer items-start gap-3">
    <input type="radio" id="{{ $id }}" {{ $attributes->class('mt-0.5 size-4 shrink-0 cursor-pointer accent-brand') }}>
    <span class="min-w-0">
        <span class="block text-sm text-fg">{{ $label ?? $slot }}</span>
        @if ($hint)<span class="mt-0.5 block text-xs text-fg-3">{{ $hint }}</span>@endif
    </span>
</label>
