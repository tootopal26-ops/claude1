<!DOCTYPE html>
<html lang="en" data-sidebar="expanded">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sign in' }} · Mini Max Admin &amp; Staff</title>
    <x-theme-boot />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-app text-fg antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
