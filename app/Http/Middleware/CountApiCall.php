<?php

namespace App\Http\Middleware;

use App\Observability\UsageMetrics;
use Closure;
use Illuminate\Http\Request;

/** ENG-24: per-tenant API call counter; runs in terminate(), after the response is sent. */
class CountApiCall
{
    public function __construct(private UsageMetrics $metrics)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        if ($request->is('api/*') && ! $request->is('api/platform/*')) {
            $this->metrics->countApiCall();
        }
    }
}
