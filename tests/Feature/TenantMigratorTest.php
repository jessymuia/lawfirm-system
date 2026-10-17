<?php

namespace Tests\Feature;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-7: resumable, per-tenant, failure-isolated migration runner. */
class TenantMigratorTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/tenmig-'.uniqid();
        mkdir($this->dir);
        foreach (['control', 'a', 'b'] as $f) {
            touch("{$this->dir}/{$f}.sqlite");
        }

        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);

        $this->tenant('firm-a', "{$this->dir}/a.sqlite");
        $this->tenant('firm-b', "{$this->dir}/b.sqlite");
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function tenant(string $slug, string $db, string $status = 'active'): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => $slug, 'status' => $status, 'database' => $db]);
    }

    private function hasTable(string $dbFile, string $table): bool
    {
        config(['database.connections.probe' => ['driver' => 'sqlite', 'database' => $dbFile, 'prefix' => '']]);
        DB::purge('probe');

        return Schema::connection('probe')->hasTable($table);
    }

    public function test_migrates_every_tenant_and_records_schema_version(): void
    {
        $this->artisan('tenants:migrate')->assertExitCode(0);

        foreach (['a', 'b'] as $f) {
            $this->assertTrue($this->hasTable("{$this->dir}/{$f}.sqlite", 'users'));
        }

        $latest = collect(glob(database_path('migrations/*.php')))->map(fn ($p) => basename($p, '.php'))->sort()->last();
        foreach (Tenant::all() as $t) {
            $this->assertSame('ok', $t->migration_status);
            $this->assertSame($latest, $t->schema_version);
        }
    }

    public function test_is_idempotent(): void
    {
        $this->artisan('tenants:migrate')->assertExitCode(0);
        $this->artisan('tenants:migrate')->assertExitCode(0);

        $this->assertSame(2, Tenant::where('migration_status', 'ok')->count());
    }

    public function test_one_failing_tenant_does_not_block_the_rest_and_is_retryable(): void
    {
        $bad = $this->tenant('firm-bad', $this->dir.'/missing-dir/bad.sqlite');

        $this->artisan('tenants:migrate')->assertExitCode(1);

        $this->assertSame('ok', Tenant::where('slug', 'firm-a')->first()->migration_status);
        $this->assertSame('ok', Tenant::where('slug', 'firm-b')->first()->migration_status);
        $bad->refresh();
        $this->assertSame('failed', $bad->migration_status);
        $this->assertNotEmpty($bad->migration_error);

        // Fix the tenant, retry only the failed one.
        mkdir($this->dir.'/missing-dir');
        touch($this->dir.'/missing-dir/bad.sqlite');
        $this->artisan('tenants:migrate --retry-failed')->assertExitCode(0);

        $bad->refresh();
        $this->assertSame('ok', $bad->migration_status);
        $this->assertNull($bad->migration_error);
    }

    public function test_tenants_may_be_at_different_versions_mid_rollout(): void
    {
        $this->artisan('tenants:migrate --tenant=firm-a')->assertExitCode(0);

        $this->assertNotNull(Tenant::where('slug', 'firm-a')->first()->schema_version);
        $this->assertNull(Tenant::where('slug', 'firm-b')->first()->schema_version);
        $this->assertFalse($this->hasTable("{$this->dir}/b.sqlite", 'users'));

        $this->artisan('tenants:status')->assertExitCode(0);
    }

    public function test_suspended_tenants_still_receive_migrations(): void
    {
        $this->tenant('firm-s', "{$this->dir}/b.sqlite", 'suspended');
        Tenant::where('slug', 'firm-b')->delete(); // avoid two tenants sharing one file

        $this->artisan('tenants:migrate --tenant=firm-s')->assertExitCode(0);
        $this->assertSame('ok', Tenant::where('slug', 'firm-s')->first()->migration_status);
    }

    public function test_process_is_not_left_pointing_at_a_tenant_database(): void
    {
        $this->artisan('tenants:migrate')->assertExitCode(0);

        $this->assertSame($this->originalDefault, DB::getDefaultConnection());
    }

    public function test_in_multi_mode_the_process_is_left_fail_closed(): void
    {
        config(['tenancy.mode' => 'multi']);

        $this->assertTrue(app(TenantMigrator::class)->migrate(Tenant::where('slug', 'firm-a')->first()));

        $this->assertSame(TenantManager::UNRESOLVED, DB::getDefaultConnection());
    }
}
