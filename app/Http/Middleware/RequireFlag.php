<?php

namespace App\Http\Middleware;

use App\Flags\Flags;
use Closure;
use Illuminate\Http\Request;

/** flag:<name>  — the route exists only for tenants with the flag on; otherwise a plain 404 (the feature "isn't there"). */
class RequireFlag
{
    public function __construct(private Flags $flags) {}

    public function handle(Request $request, Closure $next, string $flag)
    {
        abort_unless($this->flags->enabled($flag), 404);

        return $next($request);
    }
}
