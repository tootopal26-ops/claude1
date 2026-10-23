<?php

namespace App\Http\Middleware;

use App\Models\StaffUser;
use App\Support\Portal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Database session rows aren't tagged with the staff guard's user id (the
 * session handler's user tracking uses the app's default guard, which is
 * 'web' here), so deleting rows from the sessions table can't force a
 * staff account's active logins out. This checks a version stamp instead:
 * the session remembers the security_version it saw at login, and any
 * later change to that account (preset, active flag, password, super-admin
 * flag — see StaffUser::invalidateActiveSessions()) bumps the column,
 * which this middleware notices on the very next request from any of that
 * account's sessions and force-logs them out.
 */
class EnsureStaffSecurityVersionIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var StaffUser|null $staff */
        $staff = Auth::guard('staff')->user();

        if ($staff && session('staff_security_version') !== $staff->security_version) {
            $portal = Portal::fromRequest($request);

            Auth::guard('staff')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // A Livewire update can't carry flashed errors across its redirect, so say it with the
            // same flag the guest redirect uses; a normal page load flashes the specific message.
            $isLivewire = $request->hasHeader('X-Livewire');

            return redirect()->route(Portal::loginRouteName($portal), $isLivewire ? ['expired' => 1] : [])->withErrors([
                'form' => 'Your account was updated. Please sign in again.',
            ]);
        }

        return $next($request);
    }
}
