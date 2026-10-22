<img
    src="{{ asset('images/logo.png') }}"
    width="{{ round($height * 1.2) }}"
    height="{{ $height }}"
    alt="Mini Max"
    style="width: {{ round($height * 1.2) }}px; height: {{ $height }}px;"
    {{ $attributes->merge(['class' => 'inline-block align-middle shrink-0']) }}
>