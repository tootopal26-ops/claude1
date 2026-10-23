@props(['label' => 'Password', 'model' => 'password'])

@php
    $id = 'f-'.$model;
    $hasError = $errors->has($model);
@endphp

{{-- Password input with a show/hide toggle. The toggle is a real button: keyboard reachable, announces its state. --}}
<div x-data="{ show: false }">
    <x-ui.field :id="$id" :label="$label" :error="$model" required>
        <div class="relative">
            <input
                id="{{ $id }}"
                wire:model="{{ $model }}"
                :type="show ? 'text' : 'password'"
                type="password"
                autocomplete="current-password"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
                enterkeyhint="go"
                aria-required="true"
                aria-invalid="{{ $hasError ? 'true' : 'false' }}"
                @if ($hasError) aria-describedby="{{ $id }}-error" @endif
                {{ $attributes->class('mm-control h-11 pr-12') }}
            >
            <button
                type="button"
                @click="show = ! show"
                :aria-pressed="show.toString()"
                :aria-label="show ? 'Hide password' : 'Show password'"
                aria-label="Show password"
                aria-pressed="false"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-fg-3 transition-colors hover:text-fg"
            >
                <x-icon name="eye" :size="18" x-show="! show" />
                <x-icon name="eye-off" :size="18" x-show="show" x-cloak />
            </button>
        </div>
    </x-ui.field>
</div>
