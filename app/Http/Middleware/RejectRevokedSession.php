<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * SEC-4: logout must hold even when other requests were in flight. A request that started before the logout still
 * holds the logged-in session; when it finishes it writes that session back under the old id and re-sends the old
 * cookie, quietly signing the person in again (seen with the dashboard's parallel requests). Logout therefore records
 * the old session id as revoked (in the firm's own cache namespace, for the session lifetime), and any request that
 * arrives with it is treated as signed out and given a fresh session.
 */
class RejectRevokedSession
{
    public static function key(string $sessionId): string
    {
        return 'session-revoked:'.hash('sha256', $sessionId);
    }

    public static function revoke(string $sessionId): void
    {
        Cache::put(self::key($sessionId), true, now()->addMinutes((int) config('session.lifetime', 120)));
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->hasSession() && $request->session()->isStarted() && Cache::has(self::key($request->session()->getId()))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        return $next($request);
    }
}
