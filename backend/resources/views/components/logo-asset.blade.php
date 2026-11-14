@php($aspect = 800 / 647)
<img
    src="{{ asset('images/logo.png') }}"
    width="{{ round($height * $aspect) }}"
    height="{{ $height }}"
    alt="Mini Max"
    style="width: {{ round($height * $aspect) }}px; height: {{ $height }}px;"
    {{ $attributes->merge(['class' => 'inline-block align-middle shrink-0']) }}
>