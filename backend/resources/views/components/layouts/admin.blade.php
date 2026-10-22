<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Mini Max Admin' }}</title>
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('mm_theme_color');
                var font = localStorage.getItem('mm_theme_font');
                if (theme) document.documentElement.setAttribute('data-theme', theme);
                if (font) document.documentElement.setAttribute('data-font', font);
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-[#140a1f] text-white antialiased">
    @if (auth('staff')->check())
        <nav class="flex items-center justify-between border-b border-violet-900/40 bg-[#1a1025]/80 px-6 py-4">
            <div class="flex items-center gap-6">
                <a href="{{ route('admin.tournaments.index') }}" class="flex items-center gap-1.5 text-lg font-bold text-violet-300"><x-icon name="home" :size="18" />Mini Max Admin</a>
                <a href="{{ route('admin.tournaments.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="tournaments" :size="16" />Tournaments</a>
                <a href="{{ route('admin.notifications.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="notifications" :size="16" />Notifications</a>
                @if (auth('staff')->user()->hasPermission('users.view'))
                    <a href="{{ route('admin.users.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="user" :size="16" />Players</a>
                @endif
                @if (auth('staff')->user()->hasPermission('recurring_tournaments.manage'))
                    <a href="{{ route('admin.templates.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="tournaments" :size="16" />Templates</a>
                @endif
                @if (auth('staff')->user()->hasPermission('withdrawals.manage'))
                    <a href="{{ route('admin.withdrawals.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="withdrawal" :size="16" />Withdrawals</a>
                @endif
                @if (auth('staff')->user()->hasPermission('payments.view'))
                    <a href="{{ route('admin.deposits.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="deposit" :size="16" />Deposits</a>
                @endif
                @if (auth('staff')->user()->hasPermission('leaderboards.manage'))
                    <a href="{{ route('admin.leaderboards.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="leaderboard" :size="16" />Leaderboards</a>
                @endif
                @if (auth('staff')->user()->hasPermission('salary.view'))
                    <a href="{{ route('admin.staff-salary.settlements.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="salary" :size="16" />Salary</a>
                @endif
                @if (auth('staff')->user()->hasPermission('storage.view'))
                    <a href="{{ route('admin.storage.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="storage" :size="16" />Storage</a>
                @endif
                @if (auth('staff')->user()->hasPermission('staff.manage'))
                    <a href="{{ route('admin.staff.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="user" :size="16" />Staff</a>
                    <a href="{{ route('admin.presets.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="presets" :size="16" />Presets</a>
                @endif
                @if (auth('staff')->user()->hasPermission('audit_logs.view'))
                    <a href="{{ route('admin.audit-logs.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="audit" :size="16" />Audit Log</a>
                @endif
                @if (auth('staff')->user()->hasPermission('legal.manage'))
                    <a href="{{ route('admin.legal.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="audit" :size="16" />Legal</a>
                @endif
                @if (auth('staff')->user()->hasPermission('settings.manage'))
                    <a href="{{ route('admin.settings.index') }}" class="flex items-center gap-1 text-sm text-violet-300 hover:text-white"><x-icon name="presets" :size="16" />Settings</a>
                @endif
            </div>
            <div class="flex items-center gap-4 text-sm text-violet-200">
                <label class="sr-only" for="mm-theme-color-select">Color theme</label>
                <select id="mm-theme-color-select" class="rounded-lg border border-violet-800/50 bg-black/30 px-2 py-1 text-xs text-violet-200">
                    @foreach (config('minimax.theme_colors') as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <label class="sr-only" for="mm-theme-font-select">Font theme</label>
                <select id="mm-theme-font-select" class="rounded-lg border border-violet-800/50 bg-black/30 px-2 py-1 text-xs text-violet-200">
                    @foreach (config('minimax.theme_fonts') as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <span>{{ auth('staff')->user()->display_name }}</span>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="flex items-center gap-1 text-red-400 hover:text-red-300"><x-icon name="logout" :size="16" />Log out</button>
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
