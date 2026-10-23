@props(['title', 'description' => null, 'breadcrumbs' => []])

<header {{ $attributes->class('mb-6') }}>
    @if (count($breadcrumbs))
        <nav aria-label="Breadcrumb" class="mb-2">
            <ol class="flex flex-wrap items-center gap-1.5 text-xs text-fg-3">
                @foreach ($breadcrumbs as $crumb)
                    <li class="flex items-center gap-1.5">
                        @if (! $loop->first)<x-icon name="chevron-right" :size="12" class="text-fg-3/70" />@endif
                        @if (! empty($crumb['url']) && ! $loop->last)
                            <a href="{{ $crumb['url'] }}" class="-my-1 rounded px-0.5 py-1 transition-colors hover:text-fg">{{ $crumb['label'] }}</a>
                        @else
                            <span @if ($loop->last) aria-current="page" class="text-fg-2" @endif>{{ $crumb['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold tracking-tight text-fg sm:text-2xl">{{ $title }}</h1>
            @if ($description)<p class="mt-1 max-w-2xl text-sm text-fg-2">{{ $description }}</p>@endif
        </div>
        @isset($actions)
            <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</header>
