<?php

namespace App\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // TEN-3: must run before routing and before anything touches the DB.
        \App\Http\Middleware\ResolveTenant::class,
        // ENG-24: request id + tenant on every log line; per-tenant API call counter.
        \App\Http\Middleware\RequestContext::class,
        \App\Http\Middleware\SecurityHeaders::class,   // SEC-14: HSTS, CSP (report-only by default), nosniff, framing
        \App\Http\Middleware\TraceRequests::class,   // ENG-24: a trace per request, firm-tagged (after RequestContext)
        \App\Http\Middleware\CountApiCall::class,
        // PLAT-2: expired/missing license => read-only (never destructive).
        \App\Http\Middleware\ReadOnlyWhenUnlicensed::class,
        // SEC-11: terminable; records attempts by screened users to reach walled material.
        \App\Http\Middleware\LogWallAttempts::class,
        // \App\Http\Middleware\TrustHosts::class,
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            \App\Http\Middleware\RejectRevokedSession::class,   // SEC-4
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
        ],

        'api' => [
            EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\RejectRevokedSession::class,   // SEC-4: after the session has started (stateful SPA requests)
            'throttle:api',
            SubstituteBindings::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array<string, class-string|string>
     */
    protected $routeMiddleware = [
        // SEC-12: firm-required two-factor
        // PLAT-11: api.key:<scope> for the public API
        'sso.session' => \App\Http\Middleware\StartSessionForSso::class,
        'api.key' => \App\Http\Middleware\ApiKeyAuth::class,
        // SEC-12: scim.token authenticates the identity provider's provisioning calls
        'scim.token' => \App\Http\Middleware\ScimTokenAuth::class,
        'mfa.policy' => \App\Http\Middleware\EnforceMfaPolicy::class,
        'support.session' => \App\Http\Middleware\SupportSessionGuard::class,
        'platform.host' => \App\Http\Middleware\PlatformHost::class,
        'signup.host' => \App\Http\Middleware\SignupHost::class,   // PLAT-13
        'platform.auth' => \App\Http\Middleware\PlatformAuthenticate::class,
        // PLAT-1: plan gating. entitled:<feature> | entitled.limit:<limit>
        'entitled' => \App\Http\Middleware\RequireEntitlement::class,
        'portal.session' => \App\Http\Middleware\PortalSession::class,   // FUN-10
        // PLAT-3: flag:<name> (404 when off)
        'flag' => \App\Http\Middleware\RequireFlag::class,
        'entitled.limit' => \App\Http\Middleware\RequireEntitlementWithin::class,
        'authz' => \App\Http\Middleware\AuthorizeRoute::class,
        'replica' => \App\Http\Middleware\ReadFromReplica::class,   // ENG-23: read-only routes read from the replica
        'audit.document-denial' => \App\Http\Middleware\RecordDocumentAccessDenial::class,
        'authz.guard' => \App\Http\Middleware\RequireAuthzDeclaration::class,
        'api.deprecated' => \App\Http\Middleware\DeprecatedApiVersion::class,
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'auth.session' => AuthenticateSession::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'password.confirm' => RequirePassword::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'verified' => EnsureEmailIsVerified::class,
    ];
}
