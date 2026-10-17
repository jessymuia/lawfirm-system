<?php

namespace App\Http\Middleware;

use App\Platform\PlatformAudit;
use App\Platform\PlatformAuth;
use Closure;
use Illuminate\Http\Request;

/** PLAT-7: bearer-token auth + ability check (`platform.auth:operate`). Denials are audited. */
class PlatformAuthenticate
{
    public function __construct(private PlatformAuth $auth) {}

    public function handle(Request $request, Closure $next, string $ability = 'view')
    {
        $staff = $this->auth->authenticate($request->bearerToken());
        if (! $staff) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        // SEC-12: a login that still has to enrol two-factor may do nothing else.
        if ($this->auth->enrollOnly() && ! $request->is('api/platform/mfa*', 'api/platform/logout', 'api/platform/me')) {
            return response()->json(['code' => 'mfa_enrollment_required', 'message' => 'Set up two-factor authentication to continue.'], 403);
        }
        if (! $staff->can($ability)) {
            PlatformAudit::record($staff, 'denied:'.$request->method().' '.$request->path(), $request->route('slug'), [], 'denied', "needs {$ability}", $request->ip());

            return response()->json(['message' => 'Your platform role does not allow this.'], 403);
        }
        $request->attributes->set('platform_staff', $staff);

        return $next($request);
    }
}
