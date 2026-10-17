<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * ENG-24: correlation. Every log line written while serving a request (and by
 * any job it dispatches, via Laravel's Context propagation) carries the
 * request id and the tenant key, and the response echoes the request id so a
 * customer report can be matched to logs.
 *
 * Runs after ResolveTenant so the tenant is known. An inbound X-Request-Id is
 * honoured only when it is a short, inert token: anything else is replaced, so
 * callers cannot inject newlines or huge values into the logs.
 */
class RequestContext
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        Context::add([
            'request_id' => $id,
            'tenant' => $this->tenant->key(),
        ]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
