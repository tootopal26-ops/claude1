@props(['tooltip' => 'below'])

{{-- Day/Night switch. Shows the sun by day and the moon by night (CSS reads data-mode on <html>, so the glyph is right from the
     first paint) and flips instantly without a reload; the tooltip and accessible name say what a press will do. Behaviour lives
     in the `modeToggle` Alpine component (resources/js/app.js). --}}
<button
    type="button"
    x-data="modeToggle"
    @click="toggle()"
    :aria-label="label"
    aria-label="Switch to Light Mode"
    {{ $attributes->class('mm-mode-toggle group/mode size-9 shrink-0 rounded-lg text-fg-2 transition-colors hover:bg-surface-2 hover:text-fg') }}
>
    <x-icon name="sun" :size="18" class="mm-mode-toggle__sun" aria-hidden="true" />
    <x-icon name="moon" :size="18" class="mm-mode-toggle__moon" aria-hidden="true" />
    <span
        aria-hidden="true"
        x-text="label"
        @class([
            'mm-float pointer-events-none absolute right-0 z-50 hidden whitespace-nowrap rounded-md border border-line-strong px-2.5 py-1 text-xs font-medium text-fg group-hover/mode:block group-focus-visible/mode:block',
            'top-full mt-2' => $tooltip === 'below',
            'bottom-full mb-2' => $tooltip === 'above',
        ])
    ></span>
</button>
