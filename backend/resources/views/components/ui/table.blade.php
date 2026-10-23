@props(['label' => 'Data table'])

{{-- Wrapper + horizontal scroll for wide tables. Cell styling comes from .mm-table (app.css); essential columns scroll rather than hide.
     The scroll region is keyboard-focusable and named so it can be scrolled without a mouse. --}}
<div {{ $attributes->class('overflow-hidden rounded-xl border border-line bg-surface') }}>
    <div class="relative overflow-x-auto" tabindex="0" role="region" aria-label="{{ $label }}">
        <table class="mm-table">
            @isset($head)<thead>{{ $head }}</thead>@endisset
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>
    @if (isset($footer) && trim(strip_tags($footer->toHtml())) !== '')
        <div class="border-t border-line px-4 py-3">{{ $footer }}</div>
    @endif
</div>
