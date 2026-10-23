@props(['label' => null, 'hint' => null])

@php
    $model = $attributes->whereStartsWith('wire:model')->first() ?? $attributes->get('name');
    $id = $attributes->get('id') ?? 's-'.\Illuminate\Support\Str::slug(str_replace('.', '-', (string) ($model ?: \Illuminate\Support\Str::random(6)))).'-'.\Illuminate\Support\Str::random(4);
@endphp

<div>
    <label for="{{ $id }}" class="flex cursor-pointer items-start justify-between gap-4">
        <span class="min-w-0">
            <span class="block text-sm text-fg">{{ $label ?? $slot }}</span>
            @if ($hint)<span class="mt-0.5 block text-xs text-fg-3">{{ $hint }}</span>@endif
        </span>
        <span class="relative mt-0.5 inline-flex shrink-0">
            <input type="checkbox" role="switch" id="{{ $id }}" {{ $attributes->class('peer sr-only') }}>
            <span class="h-5 w-9 rounded-full border border-line-strong bg-surface-3 transition-colors peer-checked:border-brand peer-checked:bg-brand peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-(--mm-ring) peer-disabled:opacity-50"></span>
            <span class="pointer-events-none absolute left-0.5 top-0.5 size-4 rounded-full bg-fg-2 transition-transform peer-checked:translate-x-4 peer-checked:bg-on-brand motion-reduce:transition-none"></span>
        </span>
    </label>
    @if ($model && $errors->has($model))
        <p class="mt-1 text-xs text-danger" role="alert">{{ $errors->first($model) }}</p>
    @endif
</div>
