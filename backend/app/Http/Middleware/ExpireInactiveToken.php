<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SDD §5.1: tokens "expire after a defined period of inactivity." Sanctum's
 * own `expiration` config is absolute from issuance, not sliding, so this
 * checks the token's last_used_at (which Sanctum updates on every request)
 * and revokes it once the idle window has passed.
 */
class ExpireInactiveToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && $token->last_used_at) {
            $idleMinutes = (int) config('sanctum.idle_expiration', 120);

            if ($token->last_used_at->lt(now()->subMinutes($idleMinutes))) {
                $token->delete();

                abort(401, 'Session expired due to inactivity.');
            }
        }

        return $next($request);
    }
}
