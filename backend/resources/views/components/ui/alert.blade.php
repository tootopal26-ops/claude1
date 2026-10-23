@props(['variant' => 'info', 'title' => null])

@php
    $styles = [
        'info' => ['box' => 'border-info/30 bg-info-soft', 'text' => 'text-info', 'icon' => 'info'],
        'success' => ['box' => 'border-success/30 bg-success-soft', 'text' => 'text-success', 'icon' => 'check-circle'],
        'warning' => ['box' => 'border-warning/30 bg-warning-soft', 'text' => 'text-warning', 'icon' => 'alert'],
        'danger' => ['box' => 'border-danger/30 bg-danger-soft', 'text' => 'text-danger', 'icon' => 'x-circle'],
    ];
    $style = $styles[$variant] ?? $styles['info'];
@endphp

<div {{ $attributes->class(['flex gap-3 rounded-lg border px-4 py-3', $style['box']]) }} role="{{ in_array($variant, ['danger', 'warning']) ? 'alert' : 'status' }}">
    <x-icon :name="$style['icon']" :size="18" class="mt-0.5 shrink-0 {{ $style['text'] }}" />
    <div class="min-w-0 text-sm">
        @if ($title)<p class="font-semibold {{ $style['text'] }}">{{ $title }}</p>@endif
        <div class="text-fg-2">{{ $slot }}</div>
    </div>
</div>
