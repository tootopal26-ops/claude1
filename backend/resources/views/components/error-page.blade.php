@props(['code', 'title', 'description', 'detail' => null])

@php
    $signedIn = auth('staff')->check();
    $primaryHref = $signedIn ? portal_route('home') : portal_route('login');
    $primaryLabel = $signedIn ? 'Back to dashboard' : 'Go to sign in';
@endphp
<!DOCTYPE html>
<html lang="en" data-sidebar="expanded">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Mini Max</title>
    <x-theme-boot />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="mm-ambient min-h-screen bg-app text-fg antialiased">
    <main class="relative flex min-h-screen items-center justify-center overflow-hidden px-5 py-12">
        <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(50% 40% at 50% 0%, color-mix(in oklab, var(--mm-brand) 14%, transparent), transparent 70%);"></div>
        <div class="relative w-full max-w-md text-center">
            <div class="mb-8 flex items-center justify-center gap-3">
                <x-logo-asset :height="32" />
                <span class="text-base font-semibold tracking-tight">Mini Max</span>
            </div>
            <p class="text-sm font-semibold tracking-widest text-brand-text">{{ $code }}</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-fg">{{ $title }}</h1>
            <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-fg-2">{{ $description }}</p>
            @if ($detail)
                <p class="mx-auto mt-4 max-w-sm rounded-lg border border-line bg-surface px-3 py-2 text-xs text-fg-3">{{ $detail }}</p>
            @endif
            <div class="mt-8 flex flex-col justify-center gap-2 sm:flex-row">
                <x-ui.button :href="$primaryHref" size="lg">{{ $primaryLabel }}</x-ui.button>
                <x-ui.button variant="outline" size="lg" onclick="history.length > 1 ? history.back() : (window.location.href = '{{ $primaryHref }}')">Go back</x-ui.button>
            </div>
        </div>
    </main>
</body>
</html>
