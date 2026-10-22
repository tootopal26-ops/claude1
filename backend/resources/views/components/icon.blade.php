@php
    $attributeOrder = ['cx', 'cy', 'r', 'x', 'y', 'width', 'height', 'rx', 'x1', 'y1', 'x2', 'y2', 'points', 'd'];
@endphp
<svg
    {{ $attributes->merge(['width' => $size, 'height' => $size, 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => 2, 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round']) }}
>
    @foreach ($elements as [$tag, $attrs])
        <{{ $tag }}
            @foreach ($attributeOrder as $key)
                @continue(! array_key_exists($key, $attrs))
                {{ $key }}="{{ $attrs[$key] }}"
            @endforeach
        ></{{ $tag }}>
    @endforeach
</svg>
