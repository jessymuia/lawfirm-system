<?php

namespace Tests\Feature;

use App\Providers\RouteServiceProvider;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** ENG-25: a ceiling per firm, across all its users, on top of the per-user limit. */
class FirmRateLimitTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_the_firm_limit_is_shared_by_all_its_users(): void
    {
        // the ceiling of a single-firm install, or of this firm's plan in multi mode (TEN-17)
        config(['api.firm_rate_limit_single_tenant' => 3, 'api.firm_rate_limits.default' => 3, 'api.firm_rate_limits.self_hosted' => 3]);
        $a = $this->userWithPermissions([]);
        $b = $this->userWithPermissions([]);

        $this->actingAs($a)->getJson('/api/v1/profile')->assertOk();
        $this->actingAs($b)->getJson('/api/v1/profile')->assertOk();
        $this->actingAs($a)->getJson('/api/v1/profile')->assertOk();

        $this->actingAs($b)->getJson('/api/v1/profile')
            ->assertStatus(429)
            ->assertJsonPath('code', 'firm_rate_limited')
            ->assertHeader('Retry-After');
    }

    #[Group('single-mode')]
    public function test_a_single_firm_install_has_no_firm_wide_limit_by_default(): void
    {
        $this->assertNull(RouteServiceProvider::firmRateLimit());

        $user = $this->userWithPermissions([]);
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->getJson('/api/v1/profile')->assertOk();
        }
    }

    public function test_the_limit_follows_the_firms_plan(): void
    {
        config(['api.firm_rate_limits' => ['trial' => 10, 'enterprise' => 99, 'default' => 50]]);
        $manager = app(TenantManager::class);
        $set = function (?string $plan) use ($manager) {
            (new \ReflectionProperty($manager, 'current'))->setValue($manager, new Tenant(['slug' => 'x', 'plan' => $plan]));
        };

        $set('trial');
        $this->assertSame(10, RouteServiceProvider::firmRateLimit());
        $set('enterprise');
        $this->assertSame(99, RouteServiceProvider::firmRateLimit());
        $set(null);
        $this->assertSame(50, RouteServiceProvider::firmRateLimit());
        (new \ReflectionProperty($manager, 'current'))->setValue($manager, null);
    }

    public function test_the_per_user_limit_still_applies(): void
    {
        $user = $this->userWithPermissions([]);
        RateLimiter::clear('user:'.$user->id);
        for ($i = 0; $i < 300; $i++) {
            RateLimiter::hit(md5('api'.'user:'.$user->id));
        }

        $this->actingAs($user)->getJson('/api/v1/profile')->assertStatus(429);
    }
}
