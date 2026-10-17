<?php

namespace App\Http\Middleware;

use App\Observability\Tracer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * ENG-24: one trace per request, tagged with the firm and request id (RequestContext runs first), named after the
 * matched route, sent after the response (terminate). Jobs the request dispatches continue the same trace.
 */
class TraceRequests
{
    public function __construct(private Tracer $tracer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $span = $this->tracer->begin('HTTP '.$request->method(), 2, [
            'http.request.method' => $request->method(),
            'url.path' => '/'.ltrim($request->path(), '/'),
            'server.address' => $request->getHost(),
            'user_agent.original' => mb_substr((string) $request->userAgent(), 0, 200),
        ], $request->headers->get('traceparent'));
        if ($span) {
            Context::addHidden('traceparent', $this->tracer->traceparent());
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $span = $this->tracer->root();
        if (! $span) {
            return;
        }
        $route = $request->route();
        $template = $route instanceof Route ? '/'.ltrim($route->uri(), '/') : null;
        $span->name = $request->method().' '.($template ?? 'unmatched');
        $span->set(['http.route' => $template, 'http.response.status_code' => $response->getStatusCode()]);
        if ($response->getStatusCode() >= 500) {
            $span->fail('HTTP '.$response->getStatusCode());
        }
        $this->tracer->finish();
    }
}
