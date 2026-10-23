@props(['title', 'description' => null, 'icon' => 'info'])

<div {{ $attributes->class('flex flex-col items-center justify-center px-6 py-12 text-center') }}>
    <span class="mb-3 flex size-11 items-center justify-center rounded-full bg-surface-2 text-fg-3"><x-icon :name="$icon" :size="20" /></span>
    <p class="text-sm font-medium text-fg">{{ $title }}</p>
    @if ($description)<p class="mt-1 max-w-sm text-xs leading-relaxed text-fg-3">{{ $description }}</p>@endif
    @if (! $slot->isEmpty())<div class="mt-4 flex flex-wrap justify-center gap-2">{{ $slot }}</div>@endif
</div>
