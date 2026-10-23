<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\StaffUser;
use App\Support\Portal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hard boundary between the Admin and Staff portals. Super-admin accounts
 * belong to /admin/*, every other account to /staff/*; a signed-in account
 * addressing the other portal is refused here, server-side, whatever the
 * navigation happens to show. Runs after `auth:staff` and before the per-page
 * `permission:` gate, and is applied to Livewire updates too (see
 * AppServiceProvider), so a stale page can't be driven across the boundary.
 */
class EnsurePortalAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $portal): Response
    {
        /** @var StaffUser|null $staff */
        $staff = auth('staff')->user();

        if (! $staff || ! $staff->is_active) {
            abort(403, 'This account is not authorized to access this resource.');
        }

        $own = Portal::for($staff);

        if ($own !== $portal) {
            AuditLog::record('portal.access_denied', $staff, null, [
                'attempted_portal' => $portal,
                'own_portal' => $own,
                'path' => $request->path(),
            ], $request->ip());

            abort(403, $portal === Portal::ADMIN
                ? 'This area is reserved for administrators. Your account works in the Staff portal.'
                : 'This area is reserved for staff accounts. Administrators work in the Admin portal.');
        }

        return $next($request);
    }
}
