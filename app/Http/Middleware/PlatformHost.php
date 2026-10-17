<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** PLAT-7: the platform console answers only on its own host, and only in multi mode. Everywhere else it does not exist (404). */
class PlatformHost
{
    /** True when the console is also offered on the app's own address (local/demo use; never in production). */
    public static function embedded(): bool
    {
        return config('tenancy.mode') === 'multi' && (bool) config('tenancy.console_on_app_host') && ! app()->isProduction();
    }

    public static function matches(Request $request): bool
    {
        if (config('tenancy.mode') !== 'multi') {
            return false;
        }

        $host = config('tenancy.platform_host');
        if ($host && strcasecmp($request->getHost(), $host) === 0) {
            return true;
        }

        // On the app's own address only the console's own paths count, never the firm app's pages (not even "/").
        return self::embedded() && $request->is('console', 'console/*', 'api/platform/*');
    }

    public function handle(Request $request, Closure $next)
    {
        abort_unless(self::matches($request), 404);

        return $next($request);
    }
}
