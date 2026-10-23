@props(['title', 'description' => null])

{{-- A labelled group of fields: heading on the left, a card of fields on the right (stacked on narrow screens). --}}
<section {{ $attributes->class('grid gap-4 border-t border-line py-6 first:border-t-0 first:pt-0 lg:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] lg:gap-10') }}>
    <div>
        <h2 class="text-sm font-semibold text-fg">{{ $title }}</h2>
        @if ($description)<p class="mt-1 text-xs leading-relaxed text-fg-3">{{ $description }}</p>@endif
    </div>
    <div class="mm-panel rounded-xl p-5">
        <div class="grid grid-cols-1 gap-x-4 gap-y-5 sm:grid-cols-2">{{ $slot }}</div>
    </div>
</section>
