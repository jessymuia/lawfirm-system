<?php

namespace App\Http\Middleware;

use App\Signup\SignupService;
use Closure;
use Illuminate\Http\Request;

/** PLAT-13: self-service signup answers only on its own host, and only when signup is configured. Elsewhere it does not exist (404). */
class SignupHost
{
    public static function matches(Request $request): bool
    {
        $host = config('tenancy.signup_host');

        return config('tenancy.mode') === 'multi' && $host && strcasecmp($request->getHost(), $host) === 0;
    }

    public function handle(Request $request, Closure $next)
    {
        abort_unless(self::matches($request) && SignupService::available(), 404);

        return $next($request);
    }
}
