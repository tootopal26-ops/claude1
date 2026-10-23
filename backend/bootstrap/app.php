<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\EnsureSectionUnlocked;
use App\Http\Middleware\EnsureStaffSecurityVersionIsCurrent;
use App\Support\Portal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('tournaments:close-joining-window')->everyMinute();
        $schedule->command('tournaments:auto-cancel-underfilled')->everyMinute();
        $schedule->command('tournaments:generate-from-templates')->daily();
        $schedule->command('leaderboards:maintain')->everyTenMinutes();
        $schedule->command('staff-salary:maintain')->daily();
        $schedule->command('storage:cleanup')->weekly();
        $schedule->command('wallet:reconcile')->daily();
        $schedule->command('backup:run')->dailyAt('02:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'portal' => EnsurePortalAccess::class,
            'section.unlocked' => EnsureSectionUnlocked::class,
            'staff.security_version' => EnsureStaffSecurityVersionIsCurrent::class,
        ]);

        // A guest who reaches a protected page goes to the login of the portal
        // they were addressing. A Livewire update that reaches the auth guard
        // means the page was open when the session ended — say so on the login
        // screen.
        $middleware->redirectGuestsTo(fn (Request $request) => route(
            Portal::loginRouteName(Portal::fromRequest($request)),
            $request->hasHeader('X-Livewire') || $request->is('livewire*/update') ? ['expired' => 1] : [],
        ));

        // A signed-in account that opens a login page lands in its own portal.
        $middleware->redirectUsersTo(fn (Request $request) => Portal::homeUrl(Portal::for($request->user('staff')) ?? Portal::ADMIN));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
