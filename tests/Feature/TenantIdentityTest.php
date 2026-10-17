<?php

namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\IdentityService;
use App\Tenancy\Models\Identity;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** TEN-5: one login, many firms; deactivation is per firm; tenant dumps stay self-contained. */
class TenantIdentityTest extends TestCase implements \Tests\OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/tenid-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");

        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function provision(string $slug, string $email, ?string $password = null): array
    {
        return app(TenantLifecycle::class)->provision([
            'slug' => $slug,
            'name' => $slug,
            'admin_email' => $email,
            'admin_password' => $password,
            'domain' => "{$slug}.app.test",
            'database' => "{$this->dir}/{$slug}.sqlite",
        ]);
    }

    private function login(string $slug, string $email, string $password)
    {
        RateLimiter::clear(strtolower($email).'|127.0.0.1');
        config(['tenancy.mode' => 'multi']);

        return $this->postJson("http://{$slug}.app.test/api/v1/login", ['email' => $email, 'password' => $password]);
    }

    private function inTenant(string $slug, \Closure $fn)
    {
        return app(TenantManager::class)->runIn(Tenant::where('slug', $slug)->first(), $fn, allowInactive: true);
    }

    public function test_one_person_can_belong_to_two_firms_with_one_login(): void
    {
        $first = $this->provision('firm-a', 'Jane@Example.com');
        $second = $this->provision('firm-b', 'jane@example.com');

        $this->assertNotEmpty($first['admin_password']);
        $this->assertNull($second['admin_password'], 'existing login must not be regenerated');
        $this->assertSame(1, Identity::count());
        $this->assertSame(2, Identity::first()->memberships()->count());

        $pw = $first['admin_password'];
        $this->login('firm-a', 'jane@example.com', $pw)->assertOk();
        $this->login('firm-b', 'JANE@example.com', $pw)->assertOk();

        $firms = app(IdentityService::class)->firmsFor(Identity::first())->pluck('slug')->all();
        $this->assertEqualsCanonicalizing(['firm-a', 'firm-b'], $firms);
    }

    public function test_login_requires_membership_in_the_firm_being_visited(): void
    {
        $a = $this->provision('firm-a', 'jane@example.com');
        $this->provision('firm-c', 'carl@example.com');

        // Right password, but Jane has no membership in firm-c.
        $this->login('firm-c', 'jane@example.com', $a['admin_password'])->assertStatus(401);
    }

    public function test_wrong_password_and_unknown_email_are_rejected(): void
    {
        $a = $this->provision('firm-a', 'jane@example.com');

        $this->login('firm-a', 'jane@example.com', 'wrong-password')->assertStatus(401);
        $this->login('firm-a', 'nobody@example.com', $a['admin_password'])->assertStatus(401);
    }

    public function test_deactivating_a_user_in_one_firm_does_not_affect_another(): void
    {
        $a = $this->provision('firm-a', 'jane@example.com');
        $this->provision('firm-b', 'jane@example.com');
        $pw = $a['admin_password'];

        $this->inTenant('firm-a', fn () => User::where('email', 'jane@example.com')->first()->delete());

        $this->login('firm-a', 'jane@example.com', $pw)->assertStatus(401);
        $this->login('firm-b', 'jane@example.com', $pw)->assertOk();
    }

    public function test_disabled_identity_cannot_log_in_anywhere(): void
    {
        $a = $this->provision('firm-a', 'jane@example.com');
        $this->provision('firm-b', 'jane@example.com');
        Identity::first()->update(['status' => 'disabled']);

        $this->login('firm-a', 'jane@example.com', $a['admin_password'])->assertStatus(401);
        $this->login('firm-b', 'jane@example.com', $a['admin_password'])->assertStatus(401);
    }

    public function test_password_change_applies_to_every_firm(): void
    {
        $a = $this->provision('firm-a', 'jane@example.com');
        $this->provision('firm-b', 'jane@example.com');

        $user = $this->inTenant('firm-a', fn () => User::where('email', 'jane@example.com')->first());

        config(['tenancy.mode' => 'multi']);
        $this->actingAsOnHost($user, 'http://firm-a.app.test')->putJson('http://firm-a.app.test/api/v1/profile/password', [
            'current_password' => $a['admin_password'],
            'new_password' => 'A-brand-new-pass1',
            'new_password_confirmation' => 'A-brand-new-pass1',
        ])->assertOk();

        $this->login('firm-b', 'jane@example.com', 'A-brand-new-pass1')->assertOk();
        $this->login('firm-b', 'jane@example.com', $a['admin_password'])->assertStatus(401);
    }

    public function test_tenant_database_is_self_contained_and_restorable_on_its_own(): void
    {
        $this->provision('firm-a', 'jane@example.com');

        $this->inTenant('firm-a', function () {
            $conn = DB::connection('tenant');

            $this->assertSame([], $conn->select('PRAGMA foreign_key_check'), 'dangling foreign keys in tenant dump');

            $fks = collect($conn->select('PRAGMA foreign_key_list(users)'))->pluck('from')->all();
            $this->assertNotContains('global_identity_id', $fks, 'identity link must not be a cross-database FK');

            $tables = collect($conn->select("select name from sqlite_master where type='table'"))->pluck('name')->all();
            $this->assertNotContains('identities', $tables);
            $this->assertNotContains('tenants', $tables);

            // A standalone (self-hosted) restore can still authenticate: the tenant row keeps a hash.
            $u = User::where('email', 'jane@example.com')->first();
            $this->assertNotEmpty($u->password);
            $this->assertNotNull($u->global_identity_id);
        });
    }

    public function test_leftover_identity_without_memberships_gets_a_fresh_password(): void
    {
        $svc = app(IdentityService::class);

        [$identity, $first] = $svc->registerOrReuse('x@example.com');
        [$same, $second] = $svc->registerOrReuse('x@example.com');

        $this->assertSame($identity->id, $same->id);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    public function test_login_fails_closed_when_no_tenant_is_resolved(): void
    {
        $this->provision('firm-a', 'jane@example.com');
        config(['tenancy.mode' => 'multi']);

        $this->postJson('http://unknown.app.test/api/v1/login', ['email' => 'jane@example.com', 'password' => 'x'])
            ->assertNotFound();
    }
}
