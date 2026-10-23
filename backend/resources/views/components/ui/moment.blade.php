@props(['at'])

{{-- A point in time, labelled with its zone. The server runs in UTC; IST is shown beside it because that is where the platform operates. --}}
<time datetime="{{ $at->copy()->utc()->toIso8601String() }}" {{ $attributes->class('block whitespace-nowrap') }}>
    <span class="text-fg">{{ $at->copy()->utc()->format('d M Y, H:i') }} <span class="text-xs text-fg-3">UTC</span></span>
    <span class="block text-xs text-fg-3">{{ $at->copy()->timezone('Asia/Kolkata')->format('d M, H:i') }} IST</span>
</time>
