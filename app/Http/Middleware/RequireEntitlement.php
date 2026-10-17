<?php

namespace App\Http\Middleware;

use App\Entitlements\Entitlements;
use Closure;
use Illuminate\Http\Request;

/**
 *   entitled:trust_accounting   the plan must include the feature
 * A denial is an EntitlementException, rendered as 403 {code: "entitlement_required"}.
 * (Limits: see RequireEntitlementWithin.)
 */
class RequireEntitlement
{
    public function __construct(protected Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $feature)
    {
        $this->entitlements->require($feature);

        return $next($request);
    }
}
