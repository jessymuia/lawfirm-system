<?php

namespace Tests\Isolation;

use App\Models\Client;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-10: queued jobs. A job carries the firm that dispatched it; a worker whose own context is some other firm (or none) must run it
 * in that firm, and nothing it reads or writes may belong to anyone else. The queue itself is a CENTRAL store here (see the first test).
 */
class QueueIsolationTest extends IsolationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->persistentCache();
        // A central queue store, outside every firm's database: the only arrangement a multi-firm worker can drain.
        touch("{$this->dir}/queue.sqlite");
        config([
            'database.connections.queuedb' => ['driver' => 'sqlite', 'database' => "{$this->dir}/queue.sqlite", 'prefix' => '', 'foreign_key_constraints' => false],
            'queue.connections.central' => ['driver' => 'database', 'connection' => 'queuedb', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
            'queue.default' => 'central', 'queue.failed.driver' => 'null',
        ]);
        Schema::connection('queuedb')->create('jobs', function ($t) {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        IsolationProbeJob::$ran = [];
        $this->app->forgetInstance('queue'); $this->app->forgetInstance('queue.connection'); \Illuminate\Support\Facades\Queue::clearResolvedInstances();
    }

    private function work(): void
    {
        $this->app->forgetInstance('queue'); $this->app->forgetInstance('queue.connection'); \Illuminate\Support\Facades\Queue::clearResolvedInstances();
        // --memory: the worker stops after a job once the process passes its ceiling (128 MB by default), which a long test run can reach
        Artisan::call('queue:work', ['connection' => 'central', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--memory' => 4096]);
    }

    private function dispatchAs(string $slug, string $label): void
    {
        // a PendingDispatch pushes when it is destroyed, so it must be finished inside the firm, not returned out of it
        $this->inFirm($slug, function () use ($label) {
            IsolationProbeJob::dispatch($label);

            return null;
        });
        app(TenantManager::class)->failClosed();
    }

    public function test_a_job_carries_the_firm_that_dispatched_it(): void
    {
        $this->dispatchAs('a', 'from-a');
        $this->dispatchAs('b', 'from-b');

        $payloads = DB::connection('queuedb')->table('jobs')->orderBy('id')->pluck('payload')->map(fn ($p) => json_decode($p, true)['tenant'] ?? null)->all();
        $this->assertSame(['a', 'b'], $payloads);
        // and the central store is not in either firm's database
        foreach (['a', 'b'] as $slug) {
            $this->assertFalse($this->inFirm($slug, fn () => DB::table('jobs')->count() > 0), "{$slug}'s own database must not hold the other firm's queued work");
        }
    }

    public function test_each_job_runs_in_its_own_firm_and_sees_only_its_data(): void
    {
        $this->inFirm('a', fn () => Cache::put('job-key', 'cache-of-A', 600));
        $this->inFirm('b', fn () => Cache::put('job-key', 'cache-of-B', 600));
        $this->dispatchAs('a', 'from-a');
        $this->dispatchAs('b', 'from-b');
        $this->dispatchAs('a', 'from-a-2');

        $this->work();

        $ran = collect(IsolationProbeJob::$ran)->keyBy('label');
        $this->assertCount(3, $ran, 'every job must have run');
        $this->assertSame('a', $ran['from-a']['tenant']);
        $this->assertSame('b', $ran['from-b']['tenant']);
        $this->assertSame('a', $ran['from-a-2']['tenant']);
        $this->assertSame(2, $ran['from-a']['clients'], 'A\'s job must see A\'s two clients');
        $this->assertSame(5, $ran['from-b']['clients'], 'B\'s job must see B\'s five clients');
        $this->assertSame('cache-of-A', $ran['from-a']['cache']);
        $this->assertSame('cache-of-B', $ran['from-b']['cache']);
        $this->assertSame(basename($this->firmDatabase('a')), $ran['from-a']['db']);
        $this->assertSame(basename($this->firmDatabase('b')), $ran['from-b']['db']);
        $this->assertStringNotContainsString('CANARY-B', json_encode([$ran['from-a'], $ran['from-a-2']]));
        $this->assertStringNotContainsString('CANARY-A', json_encode($ran['from-b']));
    }

    public function test_a_job_writes_into_its_own_firm_only(): void
    {
        $before = ['a' => $this->inFirm('a', fn () => Client::count()), 'b' => $this->inFirm('b', fn () => Client::count())];
        $this->inFirm('b', function () {
            IsolationProbeJob::dispatch('write', writes: true);

            return null;
        });
        app(TenantManager::class)->failClosed();
        $this->work();

        $this->assertSame($before['a'], $this->inFirm('a', fn () => Client::count()), 'A must be untouched by a job B dispatched');
        $this->assertSame($before['b'] + 1, $this->inFirm('b', fn () => Client::count()));
    }

    public function test_the_workers_own_context_never_decides_which_firm_a_job_runs_in(): void
    {
        $this->dispatchAs('a', 'from-a');
        // the worker's ambient firm is B, as after a previous job
        app(TenantManager::class)->activate($this->firm['b']['tenant']);
        $this->work();

        $this->assertSame('a', IsolationProbeJob::$ran[0]['tenant']);
        $this->assertSame(2, IsolationProbeJob::$ran[0]['clients']);
    }

    public function test_the_default_connection_fails_closed_again_after_every_job(): void
    {
        $this->dispatchAs('a', 'from-a');
        $this->work();

        $this->assertSame('tenant_unresolved', DB::getDefaultConnection());
    }

    public function test_a_job_with_no_firm_is_refused_in_multi_mode(): void
    {
        $this->pushRawWithoutTenant();
        $this->work();

        $this->assertSame([], IsolationProbeJob::$ran, 'a job that names no firm must not run');
        $this->assertSame(0, DB::connection('queuedb')->table('jobs')->count(), 'and it must not be left to retry forever');
    }

    public function test_a_job_for_a_suspended_firm_is_refused(): void
    {
        $this->dispatchAs('a', 'from-a');
        $this->dispatchAs('b', 'from-b');
        app(\App\Tenancy\TenantLifecycle::class)->suspend($this->firm['b']['tenant']);

        $this->work();

        $this->assertSame(['from-a'], array_column(IsolationProbeJob::$ran, 'label'), 'only the active firm\'s job runs');
    }

    public function test_a_job_naming_an_unknown_firm_is_refused(): void
    {
        $this->pushRawWithoutTenant('ghost');
        $this->work();
        $this->assertSame([], IsolationProbeJob::$ran);
    }

    private function pushRawWithoutTenant(?string $tenant = null): void
    {
        $cmd = new IsolationProbeJob('forged');
        $payload = ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'displayName' => IsolationProbeJob::class, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'maxTries' => 1,
            'data' => ['commandName' => IsolationProbeJob::class, 'command' => serialize($cmd)]];
        if ($tenant !== null) {
            $payload['tenant'] = $tenant;
        }
        app('queue')->connection('central')->pushRaw(json_encode($payload));
    }
}

class IsolationProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var array<int,array<string,mixed>> */
    public static array $ran = [];

    public function __construct(public string $label, public bool $writes = false)
    {
    }

    public function handle(): void
    {
        if ($this->writes) {
            Client::factory()->create(['name' => 'written-by-job']);
        }
        self::$ran[] = [
            'label' => $this->label,
            'tenant' => app(TenantContext::class)->key(),
            'db' => basename(DB::getDatabaseName()),
            'clients' => Client::count(),
            'names' => Client::pluck('name')->all(),
            'cache' => Cache::get('job-key'),
        ];
    }
}
