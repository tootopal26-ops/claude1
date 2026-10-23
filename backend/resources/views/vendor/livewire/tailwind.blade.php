@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';

$pageButton = 'inline-flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-xs font-medium tabular-nums transition-colors';
@endphp

<div>
    @if ($paginator->total() > 0)
        <nav role="navigation" aria-label="Pagination" class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-fg-3">
                Showing <span class="font-medium text-fg-2">{{ $paginator->firstItem() }}</span>–<span class="font-medium text-fg-2">{{ $paginator->lastItem() }}</span>
                of <span class="font-medium text-fg-2">{{ $paginator->total() }}</span> {{ $paginator->total() === 1 ? 'result' : 'results' }}
            </p>

            @if ($paginator->hasPages())
                <div class="flex items-center gap-1">
                    {{-- Previous --}}
                    @if ($paginator->onFirstPage())
                        <span class="{{ $pageButton }} cursor-not-allowed text-fg-3/50"><x-icon name="chevron-left" :size="16" aria-hidden="true" /><span class="sr-only">{{ __('pagination.previous') }} (unavailable)</span></span>
                    @else
                        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after" class="{{ $pageButton }} text-fg-2 hover:bg-surface-2 hover:text-fg" aria-label="{{ __('pagination.previous') }}"><x-icon name="chevron-left" :size="16" /></button>
                    @endif

                    {{-- Narrow screens: just "page X of Y" --}}
                    <span class="px-2 text-xs text-fg-2 sm:hidden">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>

                    {{-- Page numbers --}}
                    <div class="hidden items-center gap-1 sm:flex">
                        @foreach ($elements as $element)
                            @if (is_string($element))
                                <span class="{{ $pageButton }} text-fg-3" aria-hidden="true">{{ $element }}</span>
                            @endif

                            @if (is_array($element))
                                @foreach ($element as $page => $url)
                                    <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                                        @if ($page == $paginator->currentPage())
                                            <span class="{{ $pageButton }} bg-brand-soft text-brand-text" aria-current="page">{{ $page }}</span>
                                        @else
                                            <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" class="{{ $pageButton }} text-fg-2 hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</button>
                                        @endif
                                    </span>
                                @endforeach
                            @endif
                        @endforeach
                    </div>

                    {{-- Next --}}
                    @if ($paginator->hasMorePages())
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}.after" class="{{ $pageButton }} text-fg-2 hover:bg-surface-2 hover:text-fg" aria-label="{{ __('pagination.next') }}"><x-icon name="chevron-right" :size="16" /></button>
                    @else
                        <span class="{{ $pageButton }} cursor-not-allowed text-fg-3/50"><x-icon name="chevron-right" :size="16" aria-hidden="true" /><span class="sr-only">{{ __('pagination.next') }} (unavailable)</span></span>
                    @endif
                </div>
            @endif
        </nav>
    @endif
</div>
