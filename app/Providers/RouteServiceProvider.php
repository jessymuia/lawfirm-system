<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            // PLAT-7: platform-staff console (host-restricted, see routes/platform.php). Registered before the
            // versioned API so /api/platform is never captured by the legacy /api alias.
            Route::middleware('api')
                ->prefix('api/platform')
                ->group(base_path('routes/platform.php'));

            // PLAT-7: a separate, host-restricted staff console. Firm users continue to use
            // the tenant-host SPA and its independent /login flow.
            Route::middleware('web')
                ->group(base_path('routes/platform-web.php'));

            // PLAT-13: self-service signup (host-restricted, see routes/signup.php).
            Route::group([], base_path('routes/signup.php'));

            // ENG-7: /api/v1 is the canonical, versioned API surface.
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api.php'));

            // Deprecated unversioned alias so existing clients keep working
            // during the migration window. Every response carries
            // Deprecation/Sunset/Link headers. See docs/API_VERSIONING.md.
            // Named with a `legacy.` prefix: duplicate route names make
            // `php artisan route:cache` (run by deploy.sh) fail.
            Route::middleware(['api', 'api.deprecated'])
                ->prefix('api')
                ->name('legacy.')
                ->group(base_path('routes/api.php'));

            // PLAT-12: tenant-independent health and status endpoints (unprefixed, no session).
            Route::group([], base_path('routes/health.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            // The SPA legitimately makes several dozen API calls while
            // loading a dashboard and its related modules. A 60-request
            // ceiling is low enough to throttle normal navigation and was
            // exhausting during the serial Playwright journey suite.
            // Keep a meaningful per-user/IP guard while allowing a normal
            // browser session to complete a workflow.
            $limits = [Limit::perMinute(300)->by('user:'.($request->user()?->id ?: $request->ip()))];

            // ENG-25: and a ceiling for the whole firm, so one tenant cannot use up the cell (see config/api.php).
            if ($perFirm = self::firmRateLimit()) {
                $limits[] = Limit::perMinute($perFirm)->by('firm:'.app(\App\Tenancy\TenantContext::class)->key())
                    ->response(fn (Request $r, array $headers) => response()->json([
                        'success' => false, 'code' => 'firm_rate_limited',
                        'message' => 'Too many requests from your firm right now. Please retry shortly.',
                    ], 429, $headers));
            }

            return $limits;
        });
    }

    /** ENG-25: requests per minute allowed for the current firm, or null for no firm-wide limit. */
    public static function firmRateLimit(): ?int
    {
        $tenant = app(\App\Tenancy\TenantManager::class)->current();
        if ($tenant === null) {
            return config('api.firm_rate_limit_single_tenant');   // one firm per installation
        }
        $limits = (array) config('api.firm_rate_limits');
        $plan = $tenant->plan;

        return (int) ($limits[$plan] ?? $limits['default'] ?? 0) ?: null;
    }
}
