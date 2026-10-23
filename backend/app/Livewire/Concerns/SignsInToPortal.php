<?php

namespace App\Livewire\Concerns;

use App\Models\AuditLog;
use App\Models\StaffUser;
use App\Support\Portal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for one portal (see App\Support\Portal). The portal comes from the
 * component, never from the request, so an Admin login can only ever open an
 * Admin session and a Staff login only a Staff one.
 *
 * - Every failure — unknown username, wrong password, deactivated account, or
 *   an account that belongs to the other portal — produces the same message,
 *   and a password hash is always checked, so neither the text nor the timing
 *   says which of those it was.
 * - Attempts are throttled per username+IP and per IP; a throttled request is
 *   rejected before any credential is looked at.
 * - No "remember me": a session restored from a remember-me cookie has no
 *   security-version stamp (EnsureStaffSecurityVersionIsCurrent), so it would
 *   be signed straight back out. Sessions end with the browser session
 *   lifetime instead.
 */
trait SignsInToPortal
{
    public string $username = '';

    public string $password = '';

    abstract protected function portal(): string;

    public function login(): void
    {
        $this->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'username.required' => 'Enter your username.',
            'password.required' => 'Enter your password.',
        ]);

        $this->ensureNotRateLimited();

        $username = trim($this->username);
        $staff = StaffUser::query()->where('username', $username)->first();

        // Always burn one password check, so an unknown username costs the same as a wrong password.
        $passwordMatches = Hash::check($this->password, $staff?->password ?? self::placeholderHash());
        $inThisPortal = $staff !== null && Portal::for($staff) === $this->portal();

        if (! $staff || ! $passwordMatches || ! $staff->is_active || ! $inThisPortal) {
            $this->recordFailure($staff, $passwordMatches && $staff?->is_active && ! $inThisPortal);

            throw ValidationException::withMessages([
                'form' => 'Those credentials do not match an active account for this portal. Check them and try again.',
            ]);
        }

        RateLimiter::clear($this->userThrottleKey());

        Auth::guard('staff')->login($staff);
        session()->regenerate();
        session(['staff_security_version' => $staff->security_version]);

        AuditLog::record('staff.login', $staff, null, ['portal' => $this->portal()], request()->ip());

        $intended = Portal::safeIntendedUrl(session()->pull('url.intended'), $this->portal());

        $this->redirect($intended ? url($intended) : Portal::homeUrl($this->portal()), navigate: false);
    }

    private function ensureNotRateLimited(): void
    {
        foreach ([[$this->userThrottleKey(), 5], [$this->ipThrottleKey(), 30]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = RateLimiter::availableIn($key);

                throw ValidationException::withMessages([
                    'form' => 'Too many sign-in attempts. Wait '.($seconds > 60 ? ceil($seconds / 60).' minutes' : $seconds.' seconds').' and try again.',
                ]);
            }
        }
    }

    private function recordFailure(?StaffUser $staff, bool $wrongPortal): void
    {
        RateLimiter::hit($this->userThrottleKey(), 300);
        RateLimiter::hit($this->ipThrottleKey(), 900);

        // Only existing accounts are named in the audit trail: a mistyped
        // username field can contain a password, which must never be logged.
        AuditLog::record(
            $wrongPortal ? 'portal.login_denied' : 'staff.login_failed',
            $staff,
            null,
            ['portal' => $this->portal()],
            request()->ip(),
        );
    }

    private function userThrottleKey(): string
    {
        return 'portal-login:'.$this->portal().':'.Str::lower(trim($this->username)).'|'.request()->ip();
    }

    private function ipThrottleKey(): string
    {
        return 'portal-login-ip:'.$this->portal().':'.request()->ip();
    }

    private static function placeholderHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }
}
