@props(['id', 'label' => null, 'hint' => null, 'error' => null, 'required' => false, 'optional' => false])

<div {{ $attributes->class('min-w-0 space-y-1.5') }}>
    @if ($label)
        <label for="{{ $id }}" class="flex items-baseline gap-1.5 text-xs font-medium text-fg-2">
            <span>{{ $label }}</span>
            @if ($required)
                <span class="text-danger" aria-hidden="true">*</span><span class="sr-only">(required)</span>
            @elseif ($optional)
                <span class="font-normal text-fg-3">(optional)</span>
            @endif
        </label>
    @endif

    {{ $slot }}

    @if ($hint)
        <p id="{{ $id }}-hint" class="text-xs leading-snug text-fg-3">{{ $hint }}</p>
    @endif

    @if ($error && $errors->has($error))
        <p id="{{ $id }}-error" class="flex items-start gap-1.5 text-xs text-danger" role="alert">
            <x-icon name="x-circle" :size="14" class="mt-px shrink-0" />
            <span>{{ $errors->first($error) }}</span>
        </p>
    @endif
</div>
