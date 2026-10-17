<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Http\Request;

/**
 * SEC-12: the browser arrives at the sign-in callback (OIDC redirect, SAML form post) FROM the identity provider, so Sanctum does not
 * treat it as the app's own frontend and starts no session, yet a successful sign-in must create one. Start it here, once, only on
 * the two callback routes. The session cookie is Lax, so the redirect that follows carries it.
 */
class StartSessionForSso
{
    public function __construct(private \Illuminate\Contracts\Container\Container $app)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->hasSession()) {
            return $next($request);   // the app's own origin: Sanctum already did this
        }

        return (new Pipeline($this->app))->send($request)
            ->through([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class])
            ->then($next);
    }
}
