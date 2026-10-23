@props(['label' => null, 'align' => 'right', 'width' => 'w-64', 'triggerClass' => ''])

{{-- Anchored popover (no teleport, so Tab order stays natural). Don't place inside overflow-hidden/scroll containers. --}}
<div
    x-data="{ open: false }"
    class="relative"
    @keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }"
    @click.outside="open = false"
>
    <button
        type="button"
        x-ref="trigger"
        @click="open = ! open"
        :aria-expanded="open"
        aria-haspopup="true"
        @if ($label) aria-label="{{ $label }}" @endif
        class="{{ $triggerClass ?: 'inline-flex h-9 items-center gap-2 rounded-lg px-2.5 text-fg-2 transition-colors hover:bg-surface-2 hover:text-fg' }}"
    >{{ $trigger }}</button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="mm-float absolute {{ $align === 'right' ? 'right-0' : 'left-0' }} top-full z-50 mt-2 {{ $width }} max-w-[calc(100vw-2rem)] rounded-xl border border-line-strong p-1.5"
    >
        {{ $slot }}
    </div>
</div>
