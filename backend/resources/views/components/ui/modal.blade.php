@props(['name', 'title', 'description' => null, 'maxWidth' => 'max-w-lg'])

{{-- Generic dialog sheet. Open with $dispatch('open-modal', 'name'); close with $dispatch('close-modal', 'name') or Escape. --}}
<div
    x-data="{ open: false }"
    x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true"
    x-on:close-modal.window="if ($event.detail === '{{ $name }}') open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[70] flex items-end justify-center p-0 sm:items-center sm:p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="modal-{{ $name }}-title"
>
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-(--mm-overlay)" @click="open = false"></div>

    <div
        x-show="open"
        x-transition
        x-trap.inert.noscroll="open"
        {{ $attributes->class(['mm-float relative max-h-[90vh] w-full overflow-y-auto rounded-t-2xl border border-line-strong p-6 sm:rounded-2xl', $maxWidth]) }}
    >
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h2 id="modal-{{ $name }}-title" class="text-base font-semibold text-fg">{{ $title }}</h2>
                @if ($description)<p class="mt-1 text-sm text-fg-2">{{ $description }}</p>@endif
            </div>
            <button type="button" @click="open = false" class="-mr-1 -mt-1 inline-flex size-8 items-center justify-center rounded-lg text-fg-3 hover:bg-surface-2 hover:text-fg" aria-label="Close dialog">
                <x-icon name="close" :size="16" />
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
