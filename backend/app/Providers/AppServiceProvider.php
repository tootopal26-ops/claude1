<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keyed by mobile+IP rather than just IP, so an attacker spraying
        // login attempts across many numbers from one IP is still limited
        // per number, and a legitimate user retyping their password from a
        // shared/NAT'd IP isn't penalized by other accounts' attempts.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower((string) $request->input('mobile')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key)->response(
                fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many login attempts. Please wait a minute and try again.',
                ], 429, $headers),
            );
        });
    }
}
