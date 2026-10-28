@props(['portal' => 'admin', 'title' => 'Sign in · Mini Max'])
<!DOCTYPE html>
{{-- Sign-in pages carry the portal's identity, so they ignore a stored theme/font preference; they follow only the Day/Night
     mode last used in this portal on this device (see components/theme-boot). --}}
<html lang="en" data-theme="purple-glass" data-font="default-sans" data-portal="{{ $portal }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="{{ $portal === 'staff' ? 'rgb(23, 19, 31)' : 'rgb(10, 8, 16)' }}">
    <title>{{ $title }}</title>
    <x-theme-boot :brand-locked="true" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh bg-app text-fg antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
