<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * ENG-7: marks the unversioned /api/* alias as deprecated.
 * Policy and sunset date live in docs/API_VERSIONING.md and config/api.php.
 */
class DeprecatedApiVersion
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Sunset', config('api.legacy_sunset'));
        $response->headers->set(
            'Link',
            '</api/v1/'.ltrim(substr($request->path(), 4), '/').'>; rel="successor-version"'
        );

        return $response;
    }
}
