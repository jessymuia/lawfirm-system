<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // Bug fix: this previously did `route('login')` when the request
        // didn't explicitly expect JSON. But this app has no dedicated
        // server-rendered login route — 'login' is the name given to the
        // catch-all SPA route ({any}), which requires a parameter. Calling
        // route('login') with none always throws UrlGenerationException,
        // turning what should be a clean 401/redirect into an unrelated
        // 500 for any unauthenticated request that doesn't send an
        // explicit Accept: application/json header (most non-fetch
        // clients, and some test/monitoring tooling). The SPA's own
        // session-expired-modal (see api.ts's response interceptor)
        // already owns the entire "you were logged out, log back in"
        // experience client-side, so there's nothing for the server to
        // redirect to — always returning null here lets Laravel's default
        // AuthenticationException handling produce a plain 401 instead.
        return null;
    }
}
