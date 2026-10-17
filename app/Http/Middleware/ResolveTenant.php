<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;

/**
 * TEN-3: host -> tenant -> connection, ahead of route dispatch (global
 * middleware). An unresolvable or inactive tenant is a hard error; there is
 * never a fallback to a default database. No-op in single-tenant mode.
 */
class ResolveTenant
{
    public function __construct(private TenantManager $tenants)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (! $this->tenants->isMulti()) {
            return $next($request);
        }

        // PLAT-12: health/status endpoints answer on any host (a load balancer probes by address) and never touch a firm.
        if ($request->is('health/live', 'health/ready', 'status', 'status.json')) {
            $this->tenants->failClosed();

            return $next($request);
        }

        // PLAT-7: the platform host serves only the staff console, which works on the control plane and activates tenants
        // explicitly, one at a time, for the action being performed. It never serves a tenant's app.
        if (PlatformHost::matches($request)) {
            abort_unless($request->is('/', 'api/platform/*', 'console', 'console/*'), 404);
            $this->tenants->failClosed();

            return $next($request);
        }

        // PLAT-13: the signup host serves only the signup page and its API; the firm is created by a job, never here.
        if (SignupHost::matches($request)) {
            abort_unless($request->is('signup', 'signup/*', 'api/signup', 'api/signup/*'), 404);
            $this->tenants->failClosed();

            return $next($request);
        }

        // Fail closed first; only a successful resolution opens a connection.
        $this->tenants->failClosed();

        $tenant = $this->tenants->findByHost($request->getHost());

        if (! $tenant) {
            // Local/demo only: an address no firm is registered on (still being set up, or reached as 127.0.0.1 / a WSL
            // address) still shows the sign-in page, so the operator can sign in. Firm APIs stay closed (404).
            if (PlatformHost::embedded() && $request->isMethod('GET') && ! $request->is('api/*', 'manifest.webmanifest')) {
                return $next($request);
            }

            abort(404);
        }

        if (! $tenant->isActive()) {
            abort(403, 'This account is not active.');
        }

        $this->tenants->activate($tenant);

        // The SPA on a tenant's own (registry-validated) domain must receive
        // Sanctum's stateful session cookies; the static env list can't know
        // about tenant domains.
        config(['sanctum.stateful' => array_values(array_unique(array_merge(
            (array) config('sanctum.stateful'),
            [$request->getHost(), $request->getHttpHost()]
        )))]);

        return $next($request);
    }
}
