<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\PlanStore;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** PLAT-4: the operator-managed plan catalogue: list, add, edit, archive, delete, and that billing really uses it. */
class PlatformPlansTest extends TestCase implements \Tests\OwnsTenancy
{
    use RefreshDatabase;

    private const HOST = 'http://admin.platform.test';

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/plans-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.mode' => 'multi',
            'tenancy.platform_host' => 'admin.platform.test',
            'tenancy.base_domain' => 'app.test',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function token(string $role = 'admin'): string
    {
        $s = PlatformStaff::where('role', $role)->first() ?? PlatformStaff::create(['name' => ucfirst($role), 'email' => "{$role}@platform.test", 'password' => Hash::make('correct-horse-battery'), 'role' => $role]);

        return $this->postJson(self::HOST.'/api/platform/login', ['email' => $s->email, 'password' => 'correct-horse-battery'])->assertOk()->json('token');
    }

    private function api(string $method, string $path, array $data = [], string $role = 'admin')
    {
        return $this->withToken($this->token($role))->json($method, self::HOST.'/api/platform'.$path, $data);
    }

    private function newPlan(array $over = []): array
    {
        return $over + ['code' => 'boutique', 'name' => 'Boutique', 'prices' => ['KES' => 1200000, 'USD' => null], 'features' => ['exports'],
            'limits' => ['users' => 10, 'storage_gb' => 20, 'audit_online_months' => 12], 'reason' => 'New plan for solo advocates'];
    }

    public function test_the_built_in_plans_are_listed_before_anything_is_saved(): void
    {
        $codes = collect($this->api('GET', '/plans')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(array_keys(config('billing.plans')), $codes);
        $this->assertSame(0, DB::connection('control')->table('plans')->count());
    }

    public function test_a_plan_can_be_added_and_billing_prices_it(): void
    {
        $this->api('POST', '/plans', $this->newPlan())->assertCreated()->assertJsonPath('data.code', 'boutique');
        PlanStore::sync(true);

        $this->assertSame(1200000, app(Billing::class)->price('boutique', 'KES'));
        $this->assertSame(10, config('entitlements.plans.boutique.limits.users'));
        $this->assertSame(20 * 1024 ** 3, config('entitlements.plans.boutique.limits.storage_bytes'));
        $this->assertContains('boutique', PlanStore::selectable());
        $this->assertTrue(PlatformAudit::where('action', 'plan.create')->exists());
    }

    public function test_editing_a_built_in_plan_changes_its_price(): void
    {
        $this->api('PUT', '/plans/starter', $this->newPlan(['name' => 'Starter', 'prices' => ['KES' => 650000, 'USD' => 3900]]))->assertOk();
        PlanStore::sync(true);

        $this->assertSame(650000, app(Billing::class)->price('starter', 'KES'));
        $this->assertTrue(PlatformAudit::where('action', 'plan.update')->exists());
    }

    public function test_a_code_cannot_be_reused_or_taken_from_the_system(): void
    {
        $this->api('POST', '/plans', $this->newPlan())->assertCreated();
        $this->api('POST', '/plans', $this->newPlan())->assertStatus(409);
        $this->api('POST', '/plans', $this->newPlan(['code' => 'self_hosted']))->assertStatus(422);
        $this->api('POST', '/plans', $this->newPlan(['prices' => ['KES' => null, 'USD' => null], 'code' => 'free_one']))->assertStatus(422);
    }

    public function test_an_archived_plan_is_not_offered_but_still_prices_existing_firms(): void
    {
        $this->api('POST', '/plans/starter/archive', ['reason' => 'No longer sold'])->assertOk()->assertJsonPath('data.archived', true);
        PlanStore::sync(true);

        $this->assertNotContains('starter', PlanStore::selectable());
        $this->assertSame(config('billing.plans.starter.KES'), app(Billing::class)->price('starter', 'KES'));
        $this->api('POST', '/plans/starter/restore', ['reason' => 'Selling it again'])->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_trial_cannot_be_archived_or_deleted(): void
    {
        $this->api('POST', '/plans/trial/archive', ['reason' => 'Trying to'])->assertStatus(409);
        $this->api('DELETE', '/plans/trial', ['reason' => 'Trying to'])->assertStatus(409);
    }

    public function test_a_plan_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        touch("{$this->dir}/acme.sqlite");
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active', 'database' => "{$this->dir}/acme.sqlite", 'schema_version' => 'x', 'migration_status' => 'ok', 'plan' => 'starter']);
        app(Billing::class)->subscribe($tenant, 'starter', 'KES');

        $this->api('DELETE', '/plans/starter', ['reason' => 'Clearing up'])->assertStatus(409);

        $this->api('POST', '/plans', $this->newPlan())->assertCreated();
        $this->api('DELETE', '/plans/boutique', ['reason' => 'Created by mistake'])->assertOk();
        $this->assertFalse(DB::connection('control')->table('plans')->where('code', 'boutique')->exists());
    }

    public function test_a_deleted_built_in_plan_does_not_come_back_from_the_config_files(): void
    {
        $this->api('DELETE', '/plans/enterprise', ['reason' => 'We do not sell it'])->assertOk();
        config(['billing.plans_synced_at' => 0]);
        PlanStore::sync(true);

        $this->assertArrayNotHasKey('enterprise', (array) config('billing.plans'));
    }

    public function test_only_operators_edit_and_only_admins_delete(): void
    {
        $this->api('GET', '/plans', [], 'viewer')->assertOk();
        $this->api('POST', '/plans', $this->newPlan(), 'viewer')->assertForbidden();
        $this->api('POST', '/plans', $this->newPlan(), 'operator')->assertCreated();
        $this->api('DELETE', '/plans/boutique', ['reason' => 'Operators may not delete'], 'operator')->assertForbidden();
    }

    public function test_the_billing_lists_cover_every_firm(): void
    {
        touch("{$this->dir}/acme.sqlite");
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active', 'database' => "{$this->dir}/acme.sqlite", 'schema_version' => 'x', 'migration_status' => 'ok', 'plan' => 'starter']);
        app(Billing::class)->subscribe($tenant, 'starter', 'KES');

        $subs = $this->api('GET', '/billing/subscriptions')->assertOk();
        $this->assertSame('acme', $subs->json('data.0.slug'));
        $this->assertSame(config('billing.plans.starter.KES'), $subs->json('summary.monthly_recurring.KES'));

        $invoices = $this->api('GET', '/billing/invoices?status=open')->assertOk();
        $this->assertSame('acme', $invoices->json('data.0.slug'));
        $this->assertGreaterThan(0, $invoices->json('data.0.amount_due'));
    }

    public function test_the_operator_sign_in_works_without_a_csrf_token_on_a_stateful_address(): void
    {
        config(['sanctum.stateful' => ['app.test']]);
        PlatformStaff::create(['name' => 'Admin', 'email' => 'admin@platform.test', 'password' => Hash::make('correct-horse-battery'), 'role' => 'admin']);

        $this->withHeaders(['Origin' => 'http://app.test', 'Referer' => 'http://app.test/login'])
            ->postJson(self::HOST.'/api/platform/login', ['email' => 'admin@platform.test', 'password' => 'correct-horse-battery'])
            ->assertOk();
    }
}
