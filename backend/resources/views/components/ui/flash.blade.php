@props(['key' => 'status', 'variant' => 'success'])

{{-- Turns a flashed session message into a toast (and keeps it for screen readers). Renders nothing when there is no message. --}}
@if (session()->has($key))
    <div
        x-data
        x-init="$nextTick(() => window.dispatchEvent(new CustomEvent('mm-toast', { detail: { message: @js(session($key)), variant: @js($variant) } })))"
        class="hidden"
    ></div>
@endif
