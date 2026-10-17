<?php

namespace App\Http\Middleware;

use App\Portal\PortalAuth;
use Closure;
use Illuminate\Http\Request;

/**
 * FUN-10: client portal requests carry `Authorization: Bearer <portal session token>`. The client it belongs to is put on
 * the request (`portal_client_id`); everything the portal returns is filtered by it. Staff logins mean nothing here.
 */
class PortalSession
{
    public function __construct(private PortalAuth $auth) {}

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->bearerToken();
        $clientId = $token !== '' ? $this->auth->clientFor($token) : null;
        if ($clientId === null) {
            return response()->json(['message' => 'Please sign in again.', 'code' => 'portal_signed_out'], 401);
        }
        $request->attributes->set('portal_client_id', $clientId);

        return $next($request);
    }
}
