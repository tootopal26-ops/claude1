@php
    /** @var \App\Models\StaffUser $staff */
    $staff = auth('staff')->user();
    $groups = \App\View\AdminNavigation::forUser($staff);
    $context = \App\View\AdminNavigation::current($groups);
    $portal = \App\View\AdminNavigation::portalLabel($staff);
    $initials = collect(preg_split('/\s+/', trim($staff->display_name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
@endphp
<!DOCTYPE html>
<html lang="en" class="group/html" data-sidebar="expanded">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ($title ?? $context['item'] ?? 'Dashboard') }} · Mini Max {{ $portal }}</title>
    <x-theme-boot />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="mm-ambient min-h-screen bg-app text-fg antialiased">
    <a href="#main" class="sr-only z-[80] rounded-lg bg-brand text-sm font-medium text-on-brand focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:px-4 focus:py-2">Skip to content</a>

    <div x-data="shell" @mm-session-expired.window="sessionExpired = true" @keydown.escape.window="drawer = false" class="min-h-screen lg:flex">
        {{-- Mobile drawer backdrop --}}
        <div x-show="drawer" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-(--mm-overlay) lg:hidden" @click="drawer = false" aria-hidden="true"></div>

        {{-- Sidebar --}}
        <aside
            id="mm-sidebar"
            aria-label="Primary"
            class="mm-glass-bar mm-glass-bar--sidebar fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] flex-col border-r border-line transition-transform duration-200 max-lg:-translate-x-full motion-reduce:transition-none lg:sticky lg:top-0 lg:z-20 lg:h-screen lg:shrink-0 lg:transition-[width] lg:group-data-[sidebar=collapsed]/html:w-[4.75rem]"
            :class="drawer && 'max-lg:translate-x-0!'"
            :inert="mobile && ! drawer"
        >
            <div class="flex h-14 shrink-0 items-center gap-3 border-b border-line px-4 lg:group-data-[sidebar=collapsed]/html:justify-center lg:group-data-[sidebar=collapsed]/html:px-0">
                <a href="{{ portal_route('home') }}" class="flex min-w-0 items-center gap-3" aria-label="Mini Max {{ $portal }} — dashboard">
                    <x-logo-asset :height="30" />
                    <span class="min-w-0 leading-tight lg:group-data-[sidebar=collapsed]/html:sr-only">
                        <span class="block truncate text-sm font-semibold text-fg">Mini Max</span>
                        <span class="block truncate text-[0.6875rem] font-medium uppercase tracking-wider text-brand-text">{{ $portal }}</span>
                    </span>
                </a>
                <button type="button" class="ml-auto inline-flex size-8 items-center justify-center rounded-lg text-fg-3 hover:bg-surface-2 hover:text-fg lg:hidden" @click="drawer = false" aria-label="Close navigation">
                    <x-icon name="close" :size="18" />
                </button>
            </div>

            <nav class="min-h-0 flex-1 overflow-y-auto px-3 py-4" aria-label="Main navigation">
                @foreach ($groups as $group)
                    <div @class(['mt-6' => ! $loop->first])>
                        <p class="mb-1.5 px-3 text-[0.6875rem] font-semibold uppercase tracking-wider text-fg-3 lg:group-data-[sidebar=collapsed]/html:hidden">{{ $group['label'] }}</p>
                        @unless ($loop->first)
                            <div class="mx-2 mb-2 hidden border-t border-line lg:group-data-[sidebar=collapsed]/html:block"></div>
                        @endunless
                        <ul class="space-y-0.5">
                            @foreach ($group['items'] as $item)
                                @php($active = request()->routeIs(...$item['active']))
                                <li>
                                    <a
                                        href="{{ route($item['route']) }}"
                                        @click="drawer = false"
                                        @mouseenter="showTip($el, @js($item['label']))"
                                        @mouseleave="hideTip()"
                                        @focus="showTip($el, @js($item['label']))"
                                        @blur="hideTip()"
                                        @if ($active) aria-current="page" @endif
                                        @class([
                                            'relative flex h-9 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors lg:group-data-[sidebar=collapsed]/html:justify-center lg:group-data-[sidebar=collapsed]/html:px-0',
                                            'bg-brand-soft text-fg' => $active,
                                            'text-fg-2 hover:bg-surface-2 hover:text-fg' => ! $active,
                                        ])
                                    >
                                        @if ($active)<span class="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-brand" aria-hidden="true"></span>@endif
                                        <x-icon :name="$item['icon']" :size="18" class="shrink-0 {{ $active ? 'text-brand-text' : '' }}" />
                                        <span class="truncate lg:group-data-[sidebar=collapsed]/html:sr-only">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>

            <div class="hidden shrink-0 border-t border-line p-3 lg:block">
                <button
                    type="button"
                    @click="toggleCollapsed()"
                    :aria-pressed="collapsed"
                    @mouseenter="showTip($el, collapsed ? 'Expand sidebar' : 'Collapse sidebar')"
                    @mouseleave="hideTip()"
                    class="flex h-9 w-full items-center gap-3 rounded-lg px-3 text-sm font-medium text-fg-3 transition-colors hover:bg-surface-2 hover:text-fg lg:group-data-[sidebar=collapsed]/html:justify-center lg:group-data-[sidebar=collapsed]/html:px-0"
                >
                    <x-icon name="panel-left" :size="18" class="shrink-0" />
                    <span class="lg:group-data-[sidebar=collapsed]/html:sr-only"><span x-text="collapsed ? 'Expand sidebar' : 'Collapse sidebar'">Collapse sidebar</span></span>
                </button>
            </div>
        </aside>

        {{-- Collapsed-sidebar tooltip: one fixed element, so the scrolling nav can never clip it.
             Visibility is a plain class toggle, not x-show: x-show reveals via setTimeout but hides in a
             microtask, so a fast hover-then-click could leave the tooltip stuck on screen. --}}
        <div class="pointer-events-none fixed z-[60] -translate-y-1/2" :style="{ left: tip.x + 'px', top: tip.y + 'px' }">
            <div role="tooltip" class="mm-float hidden whitespace-nowrap rounded-md border border-line-strong px-2.5 py-1 text-xs font-medium text-fg" :class="{ 'hidden': ! tip.show }" x-text="tip.label"></div>
        </div>

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- Header --}}
            <header class="mm-glass-bar sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b border-line px-4 sm:px-6 lg:px-8">
                <button type="button" class="inline-flex size-9 items-center justify-center rounded-lg text-fg-2 hover:bg-surface-2 hover:text-fg lg:hidden" @click="drawer = true" aria-controls="mm-sidebar" :aria-expanded="drawer" aria-label="Open navigation">
                    <x-icon name="menu" :size="20" />
                </button>

                <div class="min-w-0 flex-1">
                    @if ($context)
                        <p class="hidden truncate text-sm text-fg-3 sm:block">
                            <span>{{ $context['group'] }}</span>
                            <span class="mx-1.5 text-fg-3/60" aria-hidden="true">/</span>
                            <span class="font-medium text-fg-2">{{ $context['item'] }}</span>
                        </p>
                    @endif
                </div>

                {{-- Appearance: theme + font --}}
                <div x-data="appearance">
                    <x-ui.dropdown label="Appearance settings" width="w-72">
                        <x-slot:trigger><x-icon name="appearance" :size="18" /></x-slot:trigger>

                        <div class="px-2.5 pb-1 pt-2">
                            <p class="text-xs font-semibold text-fg">Color theme</p>
                            <div class="mt-2 grid grid-cols-1 gap-1" role="radiogroup" aria-label="Color theme">
                                @foreach (config('minimax.theme_colors') as $key => $themeLabel)
                                    <button type="button" role="radio" :aria-checked="theme === '{{ $key }}'" @click="setTheme('{{ $key }}')"
                                        class="flex h-9 items-center gap-3 rounded-lg px-2.5 text-left text-sm text-fg-2 transition-colors hover:bg-surface-2 hover:text-fg"
                                        :class="theme === '{{ $key }}' && 'bg-brand-soft text-fg'">
                                        <span data-theme="{{ $key }}" class="flex size-5 shrink-0 overflow-hidden rounded-full border border-line-strong">
                                            <span class="w-1/2 bg-surface-3"></span><span class="w-1/2 bg-brand"></span>
                                        </span>
                                        <span class="flex-1 truncate">{{ $themeLabel }}</span>
                                        <x-icon name="check" :size="14" class="text-brand-text" x-show="theme === '{{ $key }}'" x-cloak />
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-1 border-t border-line px-2.5 pb-2 pt-3">
                            <p class="text-xs font-semibold text-fg">Font</p>
                            <div class="mt-2 grid grid-cols-1 gap-1" role="radiogroup" aria-label="Font">
                                @foreach (config('minimax.theme_fonts') as $key => $fontLabel)
                                    <button type="button" role="radio" :aria-checked="font === '{{ $key }}'" @click="setFont('{{ $key }}')"
                                        class="flex h-9 items-center gap-3 rounded-lg px-2.5 text-left text-sm text-fg-2 transition-colors hover:bg-surface-2 hover:text-fg"
                                        :class="font === '{{ $key }}' && 'bg-brand-soft text-fg'">
                                        <span data-font="{{ $key }}" class="w-6 shrink-0 text-center text-base font-semibold" style="font-family: var(--mm-font-active)">Aa</span>
                                        <span class="flex-1 truncate">{{ $fontLabel }}</span>
                                        <x-icon name="check" :size="14" class="text-brand-text" x-show="font === '{{ $key }}'" x-cloak />
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </x-ui.dropdown>
                </div>

                {{-- Profile --}}
                <x-ui.dropdown width="w-72" trigger-class="inline-flex h-9 items-center gap-2.5 rounded-lg pl-1 pr-2 text-fg-2 transition-colors hover:bg-surface-2 hover:text-fg">
                    <x-slot:trigger>
                        <span class="flex size-7 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-text" aria-hidden="true">{{ $initials }}</span>
                        <span class="max-w-[10rem] truncate text-sm font-medium text-fg max-sm:sr-only">{{ $staff->display_name }}</span>
                        <span class="sr-only">account menu</span>
                        <x-icon name="chevron-down" :size="14" class="hidden text-fg-3 sm:block" />
                    </x-slot:trigger>

                    <div class="px-3 pb-3 pt-2">
                        <p class="truncate text-sm font-semibold text-fg">{{ $staff->display_name }}</p>
                        <p class="truncate text-xs text-fg-3">{{ '@'.$staff->username }}</p>
                        <dl class="mt-3 space-y-1.5 text-xs">
                            <div class="flex items-center justify-between gap-3"><dt class="text-fg-3">Portal</dt><dd class="font-medium text-fg-2">{{ $portal }}</dd></div>
                            <div class="flex items-center justify-between gap-3"><dt class="text-fg-3">Access</dt><dd class="truncate font-medium text-fg-2">{{ \App\View\AdminNavigation::roleLabel($staff) }}</dd></div>
                        </dl>
                    </div>
                    <div class="border-t border-line pt-1.5">
                        <form method="POST" action="{{ portal_route('logout') }}">
                            @csrf
                            <button type="submit" class="flex h-9 w-full items-center gap-3 rounded-lg px-3 text-sm font-medium text-danger transition-colors hover:bg-danger-soft">
                                <x-icon name="logout" :size="16" /> Log out
                            </button>
                        </form>
                    </div>
                </x-ui.dropdown>
            </header>

            <main id="main" class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8" tabindex="-1">
                <div class="mx-auto w-full max-w-[90rem]">
                    {{ $slot }}
                </div>
            </main>
        </div>

        {{-- Session expired (HTTP 419 on a Livewire request) --}}
        <div x-show="sessionExpired" x-cloak class="fixed inset-0 z-[90] flex items-center justify-center p-4" role="alertdialog" aria-modal="true" aria-labelledby="mm-expired-title" aria-describedby="mm-expired-desc">
            <div class="absolute inset-0 bg-(--mm-overlay)"></div>
            <div x-trap.inert.noscroll="sessionExpired" class="mm-float relative w-full max-w-sm rounded-2xl border border-line-strong p-6">
                <span class="mb-4 flex size-11 items-center justify-center rounded-full bg-warning-soft text-warning"><x-icon name="clock" :size="22" /></span>
                <h2 id="mm-expired-title" class="text-base font-semibold text-fg">Your session has expired</h2>
                <p id="mm-expired-desc" class="mt-1 text-sm text-fg-2">For your security you were signed out. Sign in again to continue — anything you hadn't saved on this page was not submitted.</p>
                <div class="mt-5 flex gap-2">
                    <a href="{{ portal_route('login') }}" class="inline-flex h-9 flex-1 items-center justify-center rounded-lg bg-brand px-4 text-sm font-medium text-on-brand hover:bg-brand-pressed">Sign in again</a>
                    <button type="button" class="inline-flex h-9 items-center justify-center rounded-lg border border-line-strong px-4 text-sm font-medium text-fg hover:bg-surface-2" @click="window.location.reload()">Reload</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Toasts --}}
    <div x-data="toasts" @mm-toast.window="push($event.detail)" class="pointer-events-none fixed inset-x-4 bottom-4 z-[85] flex flex-col items-end gap-2 sm:inset-x-auto sm:right-6 sm:w-96" aria-live="polite" aria-atomic="false">
        <template x-for="toast in items" :key="toast.id">
            <div x-transition class="mm-float pointer-events-auto flex w-full items-start gap-3 rounded-xl border border-line-strong p-3.5" :role="toast.variant === 'danger' ? 'alert' : 'status'">
                <span class="mt-0.5 shrink-0" :class="{ 'text-success': toast.variant === 'success', 'text-danger': toast.variant === 'danger', 'text-warning': toast.variant === 'warning', 'text-info': toast.variant === 'info' }">
                    <x-icon name="check-circle" :size="18" x-show="toast.variant === 'success'" />
                    <x-icon name="x-circle" :size="18" x-show="toast.variant === 'danger'" x-cloak />
                    <x-icon name="alert" :size="18" x-show="toast.variant === 'warning'" x-cloak />
                    <x-icon name="info" :size="18" x-show="toast.variant === 'info'" x-cloak />
                </span>
                <p class="min-w-0 flex-1 text-sm text-fg" x-text="toast.message"></p>
                <button type="button" @click="dismiss(toast.id)" class="-mr-1 -mt-1 inline-flex size-7 shrink-0 items-center justify-center rounded-md text-fg-3 hover:bg-surface-2 hover:text-fg" aria-label="Dismiss notification">
                    <x-icon name="close" :size="14" />
                </button>
            </div>
        </template>
    </div>

    {{-- Confirmation dialog (driven by data-confirm on buttons; see <x-ui.button confirm="…">) --}}
    <div x-data="confirmDialog" x-show="open" x-cloak @keydown.escape.window="if (open) cancel()" class="fixed inset-0 z-[75] flex items-end justify-center p-0 sm:items-center sm:p-4" role="alertdialog" aria-modal="true" aria-labelledby="mm-confirm-title" aria-describedby="mm-confirm-message">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-(--mm-overlay)" @click="cancel()"></div>
        <div x-show="open" x-transition x-trap.inert.noscroll="open" class="mm-float relative w-full max-w-md rounded-t-2xl border border-line-strong p-6 sm:rounded-2xl">
            <h2 id="mm-confirm-title" class="text-base font-semibold text-fg" x-text="title"></h2>
            <p id="mm-confirm-message" class="mt-2 text-sm leading-relaxed text-fg-2" x-text="message"></p>
            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" @click="cancel()" class="inline-flex h-9 items-center justify-center rounded-lg border border-line-strong px-4 text-sm font-medium text-fg hover:bg-surface-2" x-ref="cancelButton">Cancel</button>
                <button type="button" @click="accept()" class="inline-flex h-9 items-center justify-center rounded-lg px-4 text-sm font-medium"
                    :class="variant === 'danger' ? 'bg-danger-solid text-on-danger hover:bg-danger-solid-hover' : 'bg-brand text-on-brand hover:bg-brand-pressed'" x-text="confirmLabel"></button>
            </div>
        </div>
    </div>

    @livewireScripts
</body>
</html>
