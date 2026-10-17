<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-2 / TEN-3 / TEN-10: registry, fail-closed resolution, isolation. */
class TenantResolutionTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/tenancy-'.uniqid();
        mkdir($this->dir);

        foreach (['control', 'a', 'b'] as $f) {
            touch("{$this->dir}/{$f}.sqlite");
        }

        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
        ]);

        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);

        $this->makeTenant('firm-a', 'a.test', "{$this->dir}/a.sqlite");
        $this->makeTenant('firm-b', 'b.test', "{$this->dir}/b.sqlite");
        $this->makeTenant('firm-s', 's.test', "{$this->dir}/a.sqlite", 'suspended');

        config(['tenancy.mode' => 'multi']);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function makeTenant(string $slug, string $domain, string $db, string $status = 'active'): Tenant
    {
        $t = Tenant::create(['slug' => $slug, 'name' => $slug, 'status' => $status, 'database' => $db]);
        $t->domains()->create(['domain' => $domain]);

        return $t;
    }

    public function test_unknown_host_is_404_and_never_falls_back(): void
    {
        $this->get('http://ghost.test/api/v1/clients')->assertNotFound();
    }

    public function test_suspended_tenant_is_blocked(): void
    {
        $this->get('http://s.test/api/v1/clients')->assertForbidden();
    }

    public function test_host_resolves_to_its_own_tenant_and_database(): void
    {
        $probe = fn (string $host) => app(ResolveTenant::class)->handle(
            Request::create("http://{$host}/x"),
            fn () => response()->json([
                'tenant' => app(TenantContext::class)->key(),
                'db' => basename(DB::getDatabaseName()),
            ])
        )->getData(true);

        $this->assertSame(['tenant' => 'firm-a', 'db' => 'a.sqlite'], $probe('a.test'));
        $this->assertSame(['tenant' => 'firm-b', 'db' => 'b.sqlite'], $probe('b.test'));
    }

    public function test_tenant_a_data_is_invisible_to_tenant_b(): void
    {
        $m = app(TenantManager::class);

        $m->activate(Tenant::where('slug', 'firm-a')->first());
        Schema::create('notes', fn ($t) => $t->id() && $t->string('body'));
        DB::table('notes')->insert(['body' => 'A privileged note']);

        $m->activate(Tenant::where('slug', 'firm-b')->first());
        $this->assertFalse(Schema::hasTable('notes'), 'tenant B must not see tenant A tables');

        Schema::create('notes', fn ($t) => $t->id() && $t->string('body'));
        $this->assertSame(0, DB::table('notes')->count());

        $m->activate(Tenant::where('slug', 'firm-a')->first());
        $this->assertSame(1, DB::table('notes')->count());
    }

    public function test_queries_fail_closed_before_a_tenant_is_resolved(): void
    {
        app(TenantManager::class)->failClosed();

        $this->expectException(InvalidArgumentException::class);
        DB::table('users')->count();
    }

    public function test_activating_unknown_or_inactive_tenant_throws(): void
    {
        $m = app(TenantManager::class);

        try {
            $m->activateBySlug('nope');
            $this->fail('unknown tenant must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unknown tenant', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $m->activate(Tenant::where('slug', 'firm-s')->first());
    }

    public function test_queued_job_runs_under_the_tenant_that_dispatched_it(): void
    {
        // Job plumbing is exercised in single mode against the default DB.
        config(['tenancy.mode' => 'single', 'queue.default' => 'database']);
        DB::setDefaultConnection($this->originalDefault);

        TenantProbeJob::$seen = null;
        app(TenantContext::class)->activate('firm-a');
        TenantProbeJob::dispatch();

        // Worker's ambient tenant is a different one.
        app(TenantContext::class)->activate('firm-b');
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame('firm-a', TenantProbeJob::$seen);
    }
}

class TenantProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?string $seen = null;

    public function handle(): void
    {
        self::$seen = app(TenantContext::class)->key();
    }
}
