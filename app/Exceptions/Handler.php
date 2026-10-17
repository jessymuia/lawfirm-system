<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Psr\Log\LogLevel;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * ENG-24: every reported exception is tagged with its tenant and request id,
     * so an error tracker (Sentry etc.) can group and filter per tenant.
     */
    protected function context(): array
    {
        return array_merge(parent::context(), array_filter([
            'tenant' => app(\App\Tenancy\TenantContext::class)->key(),
            'request_id' => \Illuminate\Support\Facades\Context::get('request_id'),
        ]));
    }

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        // ENG-24: to the error tracker (Sentry protocol), tagged and grouped per firm; a no-op without a DSN
        $this->reportable(function (Throwable $e) {
            app(\App\Observability\ErrorReporter::class)->report($e);
        });

        // PLAT-1: a plan/limit denial is a clean, machine-readable 403 the SPA can turn into an upgrade prompt.
        $this->dontReport([\App\Compliance\LegalHoldException::class, \App\Compliance\ComplianceException::class]);
        // TEN-11: platform-owned reference rows are read-only to firms.
        $this->dontReport([\App\Catalog\SystemCatalogException::class]);
        $this->renderable(fn (\App\Catalog\SystemCatalogException $e) => response()->json(['success' => false, 'code' => 'system_catalog', 'message' => $e->getMessage()], 409));
        $this->renderable(function (\App\Compliance\LegalHoldException $e) {
            $e->recordAudit();   // a refusal inside a transaction is audited once the transaction has rolled back

            return response()->json(['success' => false, 'code' => 'legal_hold', 'message' => $e->getMessage()], 423);
        });
        $this->renderable(fn (\App\Compliance\ComplianceException $e) => response()->json(['success' => false, 'code' => 'compliance_refused', 'message' => $e->getMessage()], $e->status));
        $this->dontReport(\App\Webhooks\WebhookException::class);
        $this->renderable(fn (\App\Webhooks\WebhookException $e) => response()->json(['success' => false, 'code' => 'webhook_refused', 'message' => $e->getMessage()], $e->status));
        $this->dontReport(\App\Entitlements\EntitlementException::class);
        $this->renderable(function (\App\Entitlements\EntitlementException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'entitlement_required',
                $e->kind => $e->name,
            ], 403);
        });
    }

    /**
     * Force JSON error responses for every /api/* request, regardless of
     * the client's Accept header.
     *
     * Without this override, Laravel decides whether to return JSON or
     * HTML/redirect based on $request->expectsJson(), which depends on
     * the Accept header. Clients that don't send Accept: application/json
     * (e.g. Playwright's page.request, some HTTP libraries, curl by
     * default) fall through to Laravel's non-JSON error handling instead:
     * an AuthenticationException redirects to route('login') — which in
     * this app is the SPA's catch-all route (routes/web.php), so an
     * unauthenticated API call silently returns the frontend's index.html
     * with a 200 instead of a 401 — and other exceptions render Laravel's
     * HTML error page with a raw 500 instead of a structured JSON error.
     *
     * Since every route under /api/* is a JSON API for this SPA and
     * should never redirect or render HTML on failure, we force JSON
     * handling there unconditionally.
     */
    protected function shouldReturnJson($request, Throwable $exception)
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
