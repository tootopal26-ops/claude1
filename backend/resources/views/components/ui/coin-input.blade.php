@props(['label', 'hint' => null, 'required' => false])

@php
    $model = $attributes->whereStartsWith('wire:model')->first() ?? $attributes->get('name');
    $id = $attributes->get('id') ?? 'f-'.\Illuminate\Support\Str::slug(str_replace('.', '-', (string) $model));
    $hasError = $model && $errors->has($model);
@endphp

{{-- A whole-number coin amount: the coin artwork at the start, "coins" at the end, so the unit is never ambiguous. --}}
<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$model" :required="$required">
    <div class="relative">
        <x-coin-asset :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
        <input
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            id="{{ $id }}"
            {{ $attributes->class('mm-control pl-9 pr-16 tabular-nums')->merge(['aria-invalid' => $hasError ? 'true' : 'false', 'aria-required' => $required ? 'true' : null, 'autocomplete' => 'off']) }}
        >
        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-fg-3" aria-hidden="true">coins</span>
    </div>
</x-ui.field>
