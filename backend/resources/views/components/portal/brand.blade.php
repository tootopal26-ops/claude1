@props(['size' => 40, 'tag' => null])

{{-- Mini Max logo + wordmark, shared by every portal page. --}}
<div {{ $attributes->class('flex items-center gap-3') }}>
    <x-logo-asset :height="$size" />
    <div class="leading-none">
        <span class="block text-lg font-semibold tracking-tight text-fg">Mini Max</span>
        @if ($tag)
            <span class="mt-1 block text-[0.6875rem] font-semibold uppercase tracking-[0.22em] text-brand-text">{{ $tag }}</span>
        @endif
    </div>
</div>
