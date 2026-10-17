<?php

namespace Tests\Feature;

use App\Jobs\BuildFirmExport;
use App\Jobs\MigrateTenant;
use App\Jobs\RunTenantBackup;
use App\Jobs\RunTenantEject;
use App\Tenancy\Models\Tenant;
use App\Tenancy\PlatformJob;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-7: the fleet migration as a resumable queue of per-firm jobs, one run per firm at a time. */
class TenantMigrationQueueTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/tenq-'.uniqid();
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
        config(['tenancy.mode' => 'single']);
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function tenant(string $slug, array $attrs = []): Tenant
    {
        touch("{$this->dir}/{$slug}.sqlite");

        return Tenant::create($attrs + ['slug' => $slug, 'name' => $slug, 'status' => 'active', 'database' => "{$this->dir}/{$slug}.sqlite"]);
    }

    public function test_queue_mode_marks_firms_pending_and_queues_one_job_each(): void
    {
        Queue::fake();
        $this->tenant('a', ['migration_status' => 'ok']);
        $this->tenant('b', ['migration_status' => 'failed']);
        $this->tenant('gone', ['status' => 'offboarded']);

        $this->artisan('tenants:migrate --queue')->expectsOutputToContain('2 firm(s) queued')->assertExitCode(0);

        Queue::assertPushed(MigrateTenant::class, 2);
        Queue::assertPushed(MigrateTenant::class, fn ($job) => $job->slug === 'a');
        $this->assertSame(['pending', 'pending'], Tenant::whereIn('slug', ['a', 'b'])->orderBy('slug')->pluck('migration_status')->all());
    }

    public function test_a_firm_is_queued_at_most_once(): void
    {
        Queue::fake();
        $this->tenant('a');

        MigrateTenant::dispatch('a');
        MigrateTenant::dispatch('a');

        Queue::assertPushed(MigrateTenant::class, 1);
    }

    public function test_resume_picks_up_pending_failed_stuck_and_behind_firms_only(): void
    {
        Queue::fake();
        $latest = TenantMigrator::latestVersion();
        $this->tenant('current', ['migration_status' => 'ok', 'schema_version' => $latest]);
        $this->tenant('pending', ['migration_status' => 'pending']);
        $this->tenant('failed', ['migration_status' => 'failed', 'schema_version' => $latest]);
        $this->tenant('stuck', ['migration_status' => 'running', 'schema_version' => '2020_01_01_000000_x', 'migration_attempted_at' => now()->subHours(3)]);
        $this->tenant('working', ['migration_status' => 'running', 'schema_version' => $latest, 'migration_attempted_at' => now()->subMinutes(5)]);
        $this->tenant('behind', ['migration_status' => 'ok', 'schema_version' => '2020_01_01_000000_x']);

        $this->artisan('tenants:migrate --resume --queue')->assertExitCode(0);

        $queued = [];
        Queue::assertPushed(MigrateTenant::class, function ($job) use (&$queued) {
            $queued[] = $job->slug;

            return true;
        });
        sort($queued);
        $this->assertSame(['behind', 'failed', 'pending', 'stuck'], $queued);
    }

    public function test_queued_jobs_migrate_the_firms_in_multi_mode_without_a_firm_of_their_own(): void
    {
        config(['tenancy.mode' => 'multi']);
        $this->tenant('a');
        $this->tenant('b');

        // sync queue: the job runs now, through the same Queue::before hook a worker uses. A worker has no firm
        // between jobs (it boots fail-closed), so that is the state it must get back after each job.
        app(TenantManager::class)->failClosed();
        $this->artisan('tenants:migrate --queue')->assertExitCode(0);

        $this->assertSame(TenantManager::UNRESOLVED, DB::getDefaultConnection(), 'the worker is left fail-closed');
        $this->assertSame(['ok', 'ok'], Tenant::orderBy('slug')->pluck('migration_status')->all());
        $this->assertSame(TenantMigrator::latestVersion(), Tenant::where('slug', 'b')->value('schema_version'));
    }

    public function test_a_job_run_inline_by_a_request_hands_the_request_its_firm_back(): void
    {
        config(['tenancy.mode' => 'multi']);
        $this->tenant('a');
        $firm = $this->tenant('b');
        app(TenantManager::class)->activate($firm);

        MigrateTenant::dispatch('a');   // sync: runs inside "the request" of firm b

        $this->assertSame('b', app(TenantManager::class)->current()?->slug, 'the request still has its firm');
        $this->assertSame('b', app(TenantContext::class)->key());
        $this->assertSame(TenantManager::TENANT_CONNECTION, DB::getDefaultConnection());
        $this->assertSame('ok', Tenant::where('slug', 'a')->value('migration_status'));
    }

    public function test_a_payload_cannot_claim_to_be_a_platform_job(): void
    {
        config(['tenancy.mode' => 'multi']);
        $forged = ['platform' => true, 'tenant' => null, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => BuildFirmExport::class, 'command' => '']];

        $this->expectExceptionMessage('Queued job has no tenant');
        event(new JobProcessing('sync', new SyncJob(app(), json_encode($forged), 'sync', 'default')));
    }

    public function test_a_platform_job_is_admitted_in_multi_mode(): void
    {
        config(['tenancy.mode' => 'multi']);
        $payload = ['platform' => true, 'tenant' => null, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => MigrateTenant::class, 'command' => '']];

        event(new JobProcessing('sync', new SyncJob(app(), json_encode($payload), 'sync', 'default')));

        $this->assertSame(TenantManager::UNRESOLVED, DB::getDefaultConnection());
    }

    public function test_one_migration_per_firm_at_a_time(): void
    {
        // a store that outlives a firm switch, like redis/database/file in production (the array store is reset on each switch)
        config(['cache.default' => 'file']);
        $a = $this->tenant('a', ['migration_status' => 'pending']);
        $this->tenant('b', ['migration_status' => 'pending']);
        $held = app(TenantManager::class)->runIn($a, fn () => tap(Cache::lock('tenant-migrate', 60))->get());
        $migrator = app(TenantMigrator::class);

        $this->assertFalse($migrator->migrate($a));
        $this->assertSame('busy', $migrator->lastResult);
        $this->assertSame('pending', $a->fresh()->migration_status, 'a busy firm is left as it was');

        $this->artisan('tenants:migrate')->expectsOutputToContain('a: skipped')->expectsOutputToContain('1 ok, 0 failed, 1 busy')->assertExitCode(0);

        $held->release();
        $this->assertTrue($migrator->migrate($a->fresh()));
        $this->assertSame('ok', $migrator->lastResult);
    }

    public function test_status_reports_the_fleet_against_this_release(): void
    {
        $this->tenant('a', ['migration_status' => 'ok', 'schema_version' => TenantMigrator::latestVersion()]);
        $this->tenant('b', ['migration_status' => 'pending']);

        $this->artisan('tenants:status')->expectsOutputToContain('1 of 2 firm(s) behind; 1 pending, 0 running, 0 failed.')->assertExitCode(0);
    }

    public function test_fleet_jobs_carry_no_firm(): void
    {
        foreach ([MigrateTenant::class, RunTenantBackup::class, RunTenantEject::class] as $job) {
            $this->assertTrue(is_subclass_of($job, PlatformJob::class), $job);
        }
        $this->assertFalse(is_subclass_of(BuildFirmExport::class, PlatformJob::class));
    }
}
