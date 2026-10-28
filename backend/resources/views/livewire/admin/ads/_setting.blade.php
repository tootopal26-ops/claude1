{{-- One platform setting as a form field, by its catalog type. Expects $key (and optional $hint, $wide). --}}
@php
    $spec = \App\Domain\Settings\PlatformSettingsService::CATALOG[$key];
    $hint ??= null;
    $wide ??= false;
@endphp
@switch($spec['type'])
    @case('bool')
        <div @class(['sm:col-span-2' => $wide])>
            <x-ui.switch wire:model="values.{{ $key }}" :label="$spec['label']" :hint="$hint" />
        </div>
        @break
    @case('text')
        <x-ui.textarea :label="$spec['label']" wire:model="values.{{ $key }}" rows="3" :hint="$hint" :optional="$spec['nullable'] ?? false" wrapperClass="sm:col-span-2" />
        @break
    @case('time')
        <x-ui.input :label="$spec['label']" type="time" wire:model="values.{{ $key }}" :hint="$hint" />
        @break
    @case('date')
        <x-ui.input :label="$spec['label']" type="date" wire:model="values.{{ $key }}" :hint="$hint" :optional="$spec['nullable'] ?? false" />
        @break
    @case('int')
        <x-ui.input :label="$spec['label']" type="number" step="1" min="{{ $spec['min'] ?? 0 }}" max="{{ $spec['max'] ?? '' }}" wire:model="values.{{ $key }}" :hint="$hint" />
        @break
    @case('decimal')
    @case('percent')
        <x-ui.input :label="$spec['label']" type="number" step="0.01" min="{{ $spec['min'] ?? 0 }}" max="{{ $spec['type'] === 'percent' ? 100 : ($spec['max'] ?? '') }}" wire:model="values.{{ $key }}" :hint="$hint" />
        @break
    @default
        <x-ui.input :label="$spec['label']" wire:model="values.{{ $key }}" :hint="$hint" :optional="$spec['nullable'] ?? false" autocomplete="off" spellcheck="false" />
@endswitch
