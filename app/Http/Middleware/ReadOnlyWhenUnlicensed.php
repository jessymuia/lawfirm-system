<?php

namespace App\Http\Middleware;

use App\Entitlements\Entitlements;
use Closure;
use Illuminate\Http\Request;

/**
 * PLAT-2: expired / missing / invalid license => read-only. Reads and exports always work;
 * writes are refused with 403 {code: license_expired}. Sign-in, sign-out, password recovery
 * and license installation stay open so the customer can log in and fix it.
 * Data is never deleted or locked away. PLAT-13: a firm whose free trial ended is read-only the same way
 * (code trial_expired) until someone chooses a plan.
 */
class ReadOnlyWhenUnlicensed
{
    // Signing in (both steps), out, recovering a password, installing a license and (PLAT-13) choosing a plan stay open.
    private const EXEMPT = '#^api/(v\d+/)?(login|login/mfa|logout|password-recovery|password-reset|license|subscription/plan|portal/(login|verify|logout)|pay/callback/(mpesa/[A-Za-z0-9]+|stripe))$#';

    public function __construct(private Entitlements $entitlements)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/*') && ! $request->isMethodSafe() && ! preg_match(self::EXEMPT, $request->path())) {
            // TEN-16: a firm being moved to another cell is briefly read-only. Reads keep working.
            $tenant = app(\App\Tenancy\TenantManager::class)->current();
            if ($tenant?->read_only_reason) {
                return response()->json(['message' => $tenant->read_only_reason.' Changes are paused for a few minutes; please try again shortly.', 'code' => 'maintenance_read_only'], 503, ['Retry-After' => '60']);
            }
            $set = $this->entitlements->set();
            if ($set->readOnly) {
                return response()->json([
                    'message' => $set->notice ?? 'The system is read-only.',
                    'code' => $set->code ?? 'license_expired',
                ], 403);
            }
        }

        $response = $next($request);

        // Tell the SPA when a banner is due (grace period or read-only) without an extra call.
        if ($request->is('api/*') && ($notice = $this->entitlements->set()->notice)) {
            $response->headers->set('X-License-Notice', substr(preg_replace('/[^\x20-\x7E]/', '', $notice), 0, 300));
        }

        return $response;
    }
}
