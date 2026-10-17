<?php

namespace Tests\Feature;

use App\Entitlements\Entitlements;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PLAT-1 (SaaS source): the tenant's plan + overrides, isolated per tenant, fail-closed. */
class TenantEntitlementsTest extends TestCase implements \Tests\OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/ent-'.uniqid();
        mkdir($this->dir);
        foreach (['control', 'a', 'b', 'c', 'd'] as $f) {
            touch("{$this->dir}/{$f}.sqlite");
        }

        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.mode' => 'multi',
        ]);
        // The source is chosen at boot from the mode, so rebuild it now that the mode is multi.
        $this->app->forgetInstance(\App\Entitlements\EntitlementSource::class);
        $this->app->forgetInstance(Entitlements::class);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function tenant(string $slug, ?string $plan, ?array $overrides = null): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => $slug, 'status' => 'active', 'plan' => $plan, 'entitlements' => $overrides, 'database' => "{$this->dir}/{$slug[-1]}.sqlite"]);
    }

    private function asTenant(Tenant $t): Entitlements
    {
        app(TenantManager::class)->activate($t);

        return app(Entitlements::class);
    }

    public function test_each_tenant_gets_its_own_plan(): void
    {
        $starter = $this->tenant('firm-a', 'starter');
        $pro = $this->tenant('firm-b', 'professional');

        $this->assertFalse($this->asTenant($starter)->has('trust_accounting'));
        $this->assertTrue($this->asTenant($pro)->has('trust_accounting'));
        // and back again: the memo is per tenant, never leaking A's answer to B or vice versa
        $this->assertFalse($this->asTenant($starter)->has('trust_accounting'));
        $this->assertSame(5, $this->asTenant($starter)->limit('users'));
        $this->assertSame(25, $this->asTenant($pro)->limit('users'));
    }

    public function test_per_tenant_overrides_win_over_the_plan(): void
    {
        $t = $this->tenant('firm-a', 'starter', ['features' => ['trust_accounting' => true, 'payments' => false], 'limits' => ['users' => 40]]);
        $e = $this->asTenant($t);

        $this->assertTrue($e->has('trust_accounting'));
        $this->assertFalse($e->has('payments'));
        $this->assertSame(40, $e->limit('users'));
    }

    public function test_overrides_cannot_invent_features_or_bad_limits(): void
    {
        $t = $this->tenant('firm-a', 'starter', ['features' => ['made_up' => true], 'limits' => ['users' => 'lots', 'made_up' => 1]]);
        $e = $this->asTenant($t);

        $this->assertFalse($e->has('made_up'));
        $this->assertSame(5, $e->limit('users'), 'a non-integer override is ignored');
    }

    public function test_empty_plan_uses_the_default_plan(): void
    {
        config(['entitlements.default_plan' => 'trial']);

        $this->assertSame('trial', $this->asTenant($this->tenant('firm-a', null))->set()->plan);
        $this->assertSame(3, app(Entitlements::class)->limit('users'));
    }

    public function test_unknown_plan_on_a_tenant_grants_nothing(): void
    {
        $e = $this->asTenant($this->tenant('firm-a', 'enterprize'));

        $this->assertFalse($e->has('payments'));
        $this->assertSame(0, $e->limit('users'));
    }

    public function test_no_tenant_means_no_entitlements(): void
    {
        app(TenantManager::class)->failClosed();

        $this->assertFalse(app(Entitlements::class)->has('payments'));
    }

    public function test_changing_the_plan_takes_effect_after_forget(): void
    {
        $t = $this->tenant('firm-a', 'starter');
        $this->assertFalse($this->asTenant($t)->has('trust_accounting'));

        $t->update(['plan' => 'professional']);
        app(Entitlements::class)->forget();

        $this->assertTrue($this->asTenant($t->fresh())->has('trust_accounting'));
    }
}
