<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ENG-11: deny by default. Any route in a guarded group that does not carry
 * an `authz:*` declaration is refused with 403 before its controller runs,
 * so a forgotten check fails closed instead of leaving the endpoint open.
 */
class RequireAuthzDeclaration
{
    public function handle(Request $request, Closure $next)
    {
        $declared = collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'authz:'));

        if (! $declared) {
            Log::critical('Route has no authorization declaration; denied.', [
                'uri' => $request->route()?->uri(),
                'method' => $request->method(),
            ]);
            abort(403, 'This endpoint has no authorization policy.');
        }

        return $next($request);
    }
}
