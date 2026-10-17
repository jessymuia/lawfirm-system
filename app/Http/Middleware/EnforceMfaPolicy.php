<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Security\Mfa;
use Closure;
use Illuminate\Http\Request;

/**
 * SEC-12: when the firm requires two-factor authentication, a signed-in user without it can do nothing but enrol
 * (the /mfa routes sit outside this middleware) or sign out. Platform-support principals authenticate by one-time
 * code from the platform, whose staff have their own second factor, so they are not asked. A sign-in through the firm's SSO
 * satisfies the policy when the firm has attested that its identity provider requires a second factor.
 */
class EnforceMfaPolicy
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && self::blocks($request, $user)) {
            return response()->json([
                'success' => false, 'code' => 'mfa_enrollment_required',
                'message' => 'Your firm requires two-factor authentication. Set it up to continue.',
            ], 403);
        }

        return $next($request);
    }

    /** Whether the policy stops this user until they enrol (also reported at sign-in so the SPA goes straight there). */
    public static function blocks(Request $request, User $user): bool
    {
        return ! $user->platform_staff_id && Mfa::required() && ! Mfa::enabled($user)
            && ! ($request->hasSession() && $request->session()->get('sso_idp_mfa', false));
    }
}
