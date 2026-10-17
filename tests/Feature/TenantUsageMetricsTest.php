<?php

namespace Tests\Feature;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** ENG-24: per-tenant usage metrics (API calls counter, users/matters/storage gauges). */
class TenantUsageMetricsTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/usage-'.uniqid();
        mkdir($this->dir);
        foreach (['control', 'a', 'b'] as $f) {
            touch("{$this->dir}/{$f}.sqlite");
        }

        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);

        foreach (['a' => 'a.test', 'b' => 'b.test'] as $slug => $host) {
            $t = Tenant::create(['slug' => "firm-{$slug}", 'name' => $slug, 'status' => 'active', 'database' => "{$this->dir}/{$slug}.sqlite"]);
            $t->domains()->create(['domain' => $host]);
        }

        config(['tenancy.mode' => 'multi']);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function usage(string $slug, string $metric): int
    {
        return (int) DB::connection('control')->table('tenant_usage_daily')
            ->where('tenant_id', Tenant::where('slug', $slug)->value('id'))
            ->where('metric', $metric)->value('value');
    }

    private function seedTenant(string $slug, int $users, int $cases, int $bytes): void
    {
        app(TenantManager::class)->runIn(Tenant::where('slug', $slug)->first(), function () use ($users, $cases, $bytes) {
            Schema::create('users', fn ($t) => $t->id() && $t->softDeletes());
            Schema::create('cases', fn ($t) => $t->id() && $t->softDeletes());
            Schema::create('documents', function ($t) {
                $t->id();
                $t->double('filesize');
            });
            for ($i = 0; $i < $users; $i++) {
                DB::table('users')->insert(['deleted_at' => $i === 0 ? now() : null]); // first one is soft-deleted
            }
            for ($i = 0; $i < $cases; $i++) {
                DB::table('cases')->insert(['deleted_at' => null]);
            }
            DB::table('documents')->insert([['filesize' => $bytes], ['filesize' => 500]]);
        });
    }

    public function test_api_calls_are_counted_per_tenant(): void
    {
        $this->get('http://a.test/api/v1/clients')->assertStatus(401);
        $this->get('http://a.test/api/v1/clients')->assertStatus(401);
        $this->get('http://b.test/api/v1/clients')->assertStatus(401);

        $this->assertSame(2, $this->usage('firm-a', 'api_calls'));
        $this->assertSame(1, $this->usage('firm-b', 'api_calls'));
    }

    public function test_non_api_requests_and_unknown_hosts_are_not_counted(): void
    {
        $this->get('http://ghost.test/api/v1/clients')->assertNotFound();
        $this->get('http://a.test/some-page');

        $this->assertSame(0, DB::connection('control')->table('tenant_usage_daily')->count());
    }

    public function test_a_failing_metrics_write_never_breaks_the_request(): void
    {
        Schema::connection('control')->drop('tenant_usage_daily');

        $this->get('http://a.test/api/v1/clients')->assertStatus(401);
    }

    public function test_gauges_count_live_rows_only_and_storage_is_summed(): void
    {
        $this->seedTenant('firm-a', users: 3, cases: 2, bytes: 1500);
        $this->seedTenant('firm-b', users: 1, cases: 0, bytes: 10);

        $this->artisan('tenants:usage')->assertExitCode(0);

        $this->assertSame(2, $this->usage('firm-a', 'users'), 'soft-deleted user excluded');
        $this->assertSame(2, $this->usage('firm-a', 'matters'));
        $this->assertSame(2000, $this->usage('firm-a', 'storage_bytes'));
        $this->assertSame(0, $this->usage('firm-b', 'users'));
        $this->assertSame(510, $this->usage('firm-b', 'storage_bytes'));
    }

    public function test_rerunning_the_gauge_job_overwrites_rather_than_accumulates(): void
    {
        $this->seedTenant('firm-a', users: 3, cases: 2, bytes: 1500);

        $this->artisan('tenants:usage', ['--tenant' => 'firm-a'])->assertExitCode(0);
        $this->artisan('tenants:usage', ['--tenant' => 'firm-a'])->assertExitCode(0);

        $this->assertSame(2, $this->usage('firm-a', 'users'));
        $this->assertSame(1, DB::connection('control')->table('tenant_usage_daily')->where('metric', 'users')->count());
    }

    public function test_one_broken_tenant_does_not_stop_the_fleet(): void
    {
        // firm-a has no tables at all, so measuring it yields zeros; break it harder:
        Tenant::where('slug', 'firm-a')->update(['database' => '/nonexistent/dir/a.sqlite']);
        $this->seedTenant('firm-b', users: 2, cases: 1, bytes: 100);

        $this->artisan('tenants:usage')->assertExitCode(1);

        $this->assertSame(1, $this->usage('firm-b', 'users'), 'healthy tenant still measured (2 seeded, 1 soft-deleted)');
    }
}
