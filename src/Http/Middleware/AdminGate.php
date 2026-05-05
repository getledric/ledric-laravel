<?php

namespace Ledric\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Gates the inline admin GUI on a configurable allow-list of Laravel
 * user IDs. Any user authed through the surrounding 'auth' middleware
 * whose key isn't in the list gets a 403.
 *
 * The point of this gate is to keep the admin GUI usable from the
 * production app's existing session — nobody should have to maintain a
 * separate ledric login when they already have a Laravel one.
 */
class AdminGate
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if ($user === null) {
            abort(401, 'authentication required');
        }

        $allowed = (array) config('ledric.admin.user_ids', []);
        if (empty($allowed)) {
            abort(403, 'ledric admin GUI has no allow-listed users');
        }

        // Compare as strings — env-derived IDs come in as strings, but
        // user keys may be ints. Cast both to dodge type-juggling traps
        // (especially around UUIDs vs int IDs).
        $userKey = (string) $user->getAuthIdentifier();
        $allowed = array_map('strval', $allowed);

        if (!in_array($userKey, $allowed, true)) {
            abort(403, 'not authorized for ledric admin');
        }

        return $next($request);
    }
}
