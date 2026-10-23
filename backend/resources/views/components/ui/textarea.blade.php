@props(['label' => null, 'hint' => null, 'required' => false, 'optional' => false, 'wrapperClass' => '', 'rows' => 4])

@php
    $model = $attributes->whereStartsWith('wire:model')->first() ?? $attributes->get('name');
    $id = $attributes->get('id') ?? 'f-'.\Illuminate\Support\Str::slug(str_replace('.', '-', (string) ($model ?: \Illuminate\Support\Str::random(6))));
    $hasError = $model && $errors->has($model);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($hasError ? $id.'-error' : ''));
@endphp

<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$model" :required="$required" :optional="$optional" :class="$wrapperClass">
    <textarea
        id="{{ $id }}"
        rows="{{ $rows }}"
        {{ $attributes->class('mm-control')->merge(['aria-invalid' => $hasError ? 'true' : 'false', 'aria-describedby' => $describedBy ?: null, 'aria-required' => $required ? 'true' : null]) }}
    >{{ $slot }}</textarea>
</x-ui.field>
