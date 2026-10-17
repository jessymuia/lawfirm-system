<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * An unnamed `throttle:N,M` keys every guest by IP alone (and every signed-in person by user id), so all such routes
 * share one counter: a few login-page loads (each asks /sso/config) used up the ten tries /login/mfa allows, and the
 * person's correct code was answered 429. Each throttle carries its own prefix (`throttle:N,M,<name>`) so it counts
 * only its own route.
 */
class ThrottleKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_throttle_counts_only_its_own_route(): void
    {
        $bare = [];
        $prefixes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $m) {
                if (! is_string($m) || ! str_starts_with($m, 'throttle:')) {
                    continue;
                }
                $args = explode(',', substr($m, strlen('throttle:')));
                if (count($args) === 1) {
                    continue;   // a named limiter (throttle:api) keys itself
                }
                if (count($args) < 3 || $args[2] === '') {
                    $bare[] = implode('|', $route->methods()).' '.$route->uri().' '.$m;

                    continue;
                }
                $prefixes[$args[2]][] = $route->getActionName();   // the /api and /api/v1 mounts of one route share a budget
            }
        }

        $this->assertSame([], $bare, 'give each throttle its own prefix: throttle:N,M,<name>');
        // the probes share one budget on purpose: one group, one limit (routes/health.php)
        unset($prefixes['health']);
        foreach ($prefixes as $prefix => $uris) {
            $this->assertCount(1, array_unique($uris), "throttle prefix '{$prefix}' is shared by different actions: ".implode(', ', array_unique($uris)));
        }
    }

    public function test_loading_the_login_page_does_not_spend_the_second_step_budget(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/api/v1/sso/config')->assertOk();
        }

        // still the endpoint's own answer (an unknown challenge), not 429
        $this->postJson('/api/v1/login/mfa', ['challenge' => str_repeat('a', 64), 'code' => '123456'])->assertUnauthorized();
    }
}
