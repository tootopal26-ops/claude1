<?php

namespace App\Providers;

use App\Domain\Payments\AlphaUpiAdapter;
use App\Domain\Payments\PaymentGatewayInterface;
use App\Http\Middleware\EnsurePortalAccess;
use App\Http\Middleware\EnsureStaffSecurityVersionIsCurrent;
use App\Models\PaymentProviderConfig;
use App\Models\PlatformSetting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayInterface::class, function () {
            $config = PaymentProviderConfig::where('provider', 'alpha_upi')->first();

            return new AlphaUpiAdapter(
                (string) config('services.alpha_upi.base_url'),
                (string) config('services.alpha_upi.key'),
                (string) config('services.alpha_upi.secret'),
                (string) config('services.alpha_upi.webhook_secret'),
                $config?->webhook_signature_header ?? 'X-PayIndia-Signature',
            );
        });
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

        // Redeeming is a reward-granting, guessable-input endpoint: a few tries a minute per
        // player (and per IP, so one device can't fan out across accounts). A separate
        // counter in RedeemController closes the door after repeated wrong codes.
        RateLimiter::for('redeem', fn (Request $request) => [
            Limit::perMinute(12)->by('redeem-user:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(40)->by('redeem-ip:'.$request->ip()),
        ]);

        // Unlocking spends coins: a handful of tries a minute per player is plenty for a
        // confirm-then-buy flow, and the purchase itself is idempotent in any case.
        RateLimiter::for('section-unlock', fn (Request $request) => [
            Limit::perMinute(10)->by('section-unlock-user:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(40)->by('section-unlock-ip:'.$request->ip()),
        ]);

        // Livewire updates are separate requests from the page load. Re-apply
        // the portal boundary and the security-version check to them, so a page
        // left open can't be driven across the boundary, or keep acting after
        // its account was deactivated or its permissions changed.
        Livewire::addPersistentMiddleware([EnsurePortalAccess::class, EnsureStaffSecurityVersionIsCurrent::class]);

        $this->overrideConfigFromPlatformSettings();
    }

    /**
     * Lets staff change a fixed set of config('minimax.*') values from
     * the Admin panel without a deploy (see PlatformSettingsService for
     * which keys and why). Guarded by hasTable() because this runs on
     * every boot, including `migrate` itself before this table exists
     * on a fresh install.
     */
    private function overrideConfigFromPlatformSettings(): void
    {
        if (! $this->app->bound('db') || ! Schema::hasTable('platform_settings')) {
            return;
        }

        foreach (PlatformSetting::query()->pluck('value', 'key') as $key => $value) {
            config(["minimax.{$key}" => $value]);
        }
    }
}
