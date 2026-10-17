<?php

namespace Tests;

use App\Entitlements\Entitlements;
use App\Models\User;
use App\Tenancy\Models\Identity;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** TEN-17: TEST_TENANCY_MODE=multi runs every test inside one provisioned firm (see setUpTraits). */
    public static function multiMode(): bool
    {
        return getenv('TEST_TENANCY_MODE') === 'multi';
    }

    /** @var array{dir:string, control:string, tenant:string}|null pristine databases, built once per process */
    private static ?array $multiPristine = null;

    /**
     * TEN-17: in multi mode the suite runs the way SaaS runs: a control plane and one firm, each its own SQLite
     * database on its own connection, the firm resolved from the request host (`localhost`) by the real middleware.
     * The control plane and the firm database are migrated once per process and copied fresh for every test (instead
     * of RefreshDatabase's transaction, which cannot span the two connections).
     */
    protected function setUpTraits()
    {
        if (! self::multiMode() || $this instanceof OwnsTenancy) {
            return parent::setUpTraits();
        }
        $uses = array_flip(class_uses_recursive(static::class));
        if (isset($uses[RefreshDatabase::class])) {
            $this->refreshMultiTenantDatabases();
            unset($uses[RefreshDatabase::class]);
        }
        $this->traitsUsedByTest = $uses;

        return parent::setUpTraits();
    }

    private function refreshMultiTenantDatabases(): void
    {
        $p = self::$multiPristine ??= $this->buildMultiTenantDatabases();
        foreach (['control', 'tenant'] as $db) {
            copy($p[$db], "{$p['dir']}/{$db}.sqlite");
        }
        $this->configureMultiTenant($p['dir']);
        $manager = app(TenantManager::class);
        $tenant = $manager->findBySlug('testfirm');
        $manager->activate($tenant);
        $connection = config('database.connections.'.TenantManager::TENANT_CONNECTION);

        // Fleet commands and firm-less requests (health, platform console) leave the process fail-closed, as they must.
        // The test then carries on in its firm, as the next request in production would. Restored without a query, so
        // tests that count queries (e.g. "liveness touches nothing") see only the request's own.
        $back = function () use ($manager, $tenant, $connection) {
            if (DB::getDefaultConnection() !== TenantManager::UNRESOLVED) {
                return;
            }
            app(TenantContext::class)->activate($tenant->slug);
            config(['database.connections.'.TenantManager::TENANT_CONNECTION => $connection]);
            DB::setDefaultConnection(TenantManager::TENANT_CONNECTION);
            (fn () => $this->current = $tenant)->call($manager);
        };
        $console = app(Kernel::class);
        $console->rerouteSymfonyCommandEvents();   // CommandFinished also for Artisan::call() ...
        $console->setArtisan(null);                // ... on an Artisan instance built after it
        Event::listen(CommandFinished::class, $back);
        Event::listen(RequestHandled::class, $back);

        // In a SaaS firm every person signs in through a control-plane identity; factory-made users get one too.
        User::created(function (User $user) use ($manager) {
            $tenant = $manager->current();
            if (! $tenant || ! $user->email || $user->global_identity_id) {
                return;
            }
            $identity = Identity::firstOrCreate(['email' => strtolower(trim($user->email))], ['password' => $user->password]);
            TenantMembership::updateOrCreate(['identity_id' => $identity->id, 'tenant_id' => $tenant->id], ['tenant_user_id' => $user->id]);
            $user->forceFill(['global_identity_id' => $identity->id])->saveQuietly();
        });
        // a test that sets a password on the user row means "this person's password"; in SaaS that lives on the identity
        User::updated(function (User $user) {
            if ($user->global_identity_id && $user->wasChanged(['password', 'email'])) {
                Identity::whereKey($user->global_identity_id)->update(['password' => $user->password, 'email' => strtolower(trim((string) $user->email))]);
            }
        });
    }

    private function configureMultiTenant(string $dir): void
    {
        config([
            'tenancy.mode' => 'multi',
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
        ]);
        DB::purge('control');
    }

    /** @return array{dir:string, control:string, tenant:string} */
    private function buildMultiTenantDatabases(): array
    {
        $dir = sys_get_temp_dir().'/multi-'.getmypid().'-'.bin2hex(random_bytes(3));
        @mkdir("{$dir}/pristine", 0777, true);
        touch("{$dir}/control.sqlite");
        touch("{$dir}/tenant.sqlite");
        $this->configureMultiTenant($dir);

        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        config(['database.connections.firm_setup' => ['driver' => 'sqlite', 'database' => "{$dir}/tenant.sqlite", 'prefix' => '', 'foreign_key_constraints' => true]]);
        Artisan::call('migrate', ['--database' => 'firm_setup', '--force' => true]);
        DB::purge('firm_setup');

        $tenant = Tenant::create(['slug' => 'testfirm', 'name' => 'Test Firm', 'status' => 'active', 'plan' => 'self_hosted',
            'database' => "{$dir}/tenant.sqlite", 'migration_status' => 'ok']);
        // the hosts tests send requests to (firm.example.com: TrustedProxiesTest's forwarded host)
        foreach (['localhost', '127.0.0.1', 'firm.example.com'] as $host) {
            $tenant->domains()->create(['domain' => $host]);
        }
        DB::purge('control');

        copy("{$dir}/control.sqlite", "{$dir}/pristine/control.sqlite");
        copy("{$dir}/tenant.sqlite", "{$dir}/pristine/tenant.sqlite");

        return ['dir' => $dir, 'control' => "{$dir}/pristine/control.sqlite", 'tenant' => "{$dir}/pristine/tenant.sqlite"];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Simulate requests coming from the SPA frontend so Sanctum's
        // EnsureFrontendRequestsAreStateful treats them as stateful and
        // attaches the session — the same path real browser traffic takes.
        $this->withHeader('Referer', 'http://localhost');

        // Seed the app's full permission catalog before every test, not
        // just tests that go through adminUser(). Spatie's
        // hasPermissionTo() throws PermissionDoesNotExist for any
        // permission that has never been created as a Permission row —
        // it isn't enough for a user to simply lack the permission, the
        // permission itself has to exist in the table first. Without
        // this, any test using userWithPermissions() or a plain
        // User::factory()->create() — i.e. most of them — would throw
        // that exception the moment a controller checked a permission
        // that test hadn't explicitly pre-created, well before ever
        // reaching the intended 401/403/200 assertion.
        // Tests that never migrate (no RefreshDatabase: pure command or HTTP smoke tests) have no
        // permissions table to seed, so skip it there instead of failing in setUp.
        if (Schema::hasTable('permissions')) {
            Artisan::call('law:setup-permissions');
        }
    }

    /** Options that point a firm-scoped console command at the test firm: none on a single-firm install (TEN-17). */
    protected function firmOption(): array
    {
        return self::multiMode() ? ['--tenant' => 'testfirm'] : [];
    }

    /** Switch the firm's plan: the install's plan on a single-firm install, the firm's registry row in multi mode (TEN-17). */
    protected function usePlan(string $plan): void
    {
        config(['entitlements.single_tenant_plan' => $plan]);
        $tenant = app(TenantManager::class)->current();
        if (self::multiMode() && $tenant) {
            $tenant->update(['plan' => $plan]);
        }
        app(Entitlements::class)->forget();
    }

    /**
     * Multi-tenant tests: act as $user on the firm that owns $host. TEN-10 makes a firm switch drop the previous firm's login (as it must,
     * in any process that outlives a request), so the firm is made active first and the request then finds it already current. In
     * production a login is made after the host has been resolved, so this order is how a real request behaves.
     */
    protected function actingAsOnHost($user, string $host)
    {
        $tenants = app(TenantManager::class);
        $tenant = $tenants->findByHost(parse_url($host, PHP_URL_HOST) ?: $host);
        $this->assertNotNull($tenant, "no firm owns {$host}");
        $tenants->activate($tenant);

        return $this->actingAs($user);
    }
}
