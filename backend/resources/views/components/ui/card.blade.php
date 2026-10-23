@props(['title' => null, 'description' => null, 'padding' => true])

<section {{ $attributes->class('mm-panel rounded-xl') }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
            <div class="min-w-0">
                @if ($title)<h2 class="text-sm font-semibold text-fg">{{ $title }}</h2>@endif
                @if ($description)<p class="mt-0.5 text-xs text-fg-3">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif

    <div @class(['p-5' => $padding])>{{ $slot }}</div>
</section>
