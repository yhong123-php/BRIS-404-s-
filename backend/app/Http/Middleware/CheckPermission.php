<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC gate per SDD §5.2: every admin endpoint declares the permission it
 * requires (e.g. residents.create); this runs before the controller so no
 * route can bypass the role/permission matrix in Table 9.0.
 */
class CheckPermission
{
    /**
     * Accepts one or more permission names; passes if the user holds any of
     * them (e.g. `permission:a6.manage,a6.manage_health_linked`).
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        $allowed = $user
            && method_exists($user, 'hasPermission')
            && collect($permissions)->contains(fn (string $permission) => $user->hasPermission($permission));

        if (! $allowed) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
