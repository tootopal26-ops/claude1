<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Mini Max Admin' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-[#140a1f] text-white antialiased">
    @if (auth('staff')->check())
        <nav class="flex items-center justify-between border-b border-violet-900/40 bg-[#1a1025]/80 px-6 py-4">
            <div class="flex items-center gap-6">
                <a href="{{ route('admin.tournaments.index') }}" class="text-lg font-bold text-violet-300">Mini Max Admin</a>
                <a href="{{ route('admin.tournaments.index') }}" class="text-sm text-violet-300 hover:text-white">Tournaments</a>
                <a href="{{ route('admin.notifications.index') }}" class="text-sm text-violet-300 hover:text-white">Notifications</a>
                @if (auth('staff')->user()->hasPermission('users.view'))
                    <a href="{{ route('admin.users.index') }}" class="text-sm text-violet-300 hover:text-white">Players</a>
                @endif
                @if (auth('staff')->user()->hasPermission('recurring_tournaments.manage'))
                    <a href="{{ route('admin.templates.index') }}" class="text-sm text-violet-300 hover:text-white">Templates</a>
                @endif
                @if (auth('staff')->user()->hasPermission('staff.manage'))
                    <a href="{{ route('admin.staff.index') }}" class="text-sm text-violet-300 hover:text-white">Staff</a>
                    <a href="{{ route('admin.presets.index') }}" class="text-sm text-violet-300 hover:text-white">Presets</a>
                @endif
                @if (auth('staff')->user()->hasPermission('audit_logs.view'))
                    <a href="{{ route('admin.audit-logs.index') }}" class="text-sm text-violet-300 hover:text-white">Audit Log</a>
                @endif
            </div>
            <div class="flex items-center gap-4 text-sm text-violet-200">
                <span>{{ auth('staff')->user()->display_name }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="text-red-400 hover:text-red-300">Log out</button>
                </form>
            </div>
        </nav>
    @endif

    <main class="mx-auto max-w-5xl px-6 py-8">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
