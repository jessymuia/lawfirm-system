<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** entitled.limit:users  creating one more must stay within the plan's limit. */
class RequireEntitlementWithin extends RequireEntitlement
{
    public function handle(Request $request, Closure $next, string $limit)
    {
        $this->entitlements->requireWithin($limit);

        return $next($request);
    }
}
