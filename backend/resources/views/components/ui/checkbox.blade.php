@props(['label' => null, 'hint' => null, 'wrapperClass' => ''])

@php
    $model = $attributes->whereStartsWith('wire:model')->first() ?? $attributes->get('name');
    $id = $attributes->get('id') ?? 'f-'.\Illuminate\Support\Str::slug(str_replace('.', '-', (string) ($model ?: \Illuminate\Support\Str::random(6)))).'-'.\Illuminate\Support\Str::random(4);
@endphp

<div class="{{ $wrapperClass }}">
    <label for="{{ $id }}" class="flex cursor-pointer items-start gap-3">
        <input type="checkbox" id="{{ $id }}" {{ $attributes->class('mt-0.5 size-4 shrink-0 cursor-pointer rounded border-line-strong bg-surface-2 accent-brand') }}>
        <span class="min-w-0">
            <span class="block text-sm text-fg">{{ $label ?? $slot }}</span>
            @if ($hint)<span class="mt-0.5 block text-xs text-fg-3">{{ $hint }}</span>@endif
        </span>
    </label>
    @if ($model && $errors->has($model))
        <p class="mt-1 text-xs text-danger" role="alert">{{ $errors->first($model) }}</p>
    @endif
</div>
