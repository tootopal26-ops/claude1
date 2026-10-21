<?php

namespace App\Http\Middleware;

use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny-by-default RBAC gate for /admin and /staff routes.
 *
 * Unauthenticated or inactive staff accounts are rejected outright; an
 * authenticated staff account is only let through when it is a super
 * admin or its assigned preset explicitly grants the given permission
 * key. Missing a matching permission is always a denial, never an
 * implicit allow.
 */
class EnsurePermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var StaffUser|null $staff */
        $staff = auth('staff')->user();

        if (! $staff || ! $staff->is_active) {
            abort(403, 'This account is not authorized to access this resource.');
        }

        if (! $staff->hasPermission($permission)) {
            abort(403, "Missing required permission: {$permission}");
        }

        return $next($request);
    }
}
