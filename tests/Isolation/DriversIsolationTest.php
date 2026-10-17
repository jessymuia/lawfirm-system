<?php

namespace Tests\Isolation;

use App\Models\Client;
use App\Models\Document;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TEN-10: the suite on the production drivers (MySQL, Redis, S3), where firms share one server, one Redis and one
 * bucket and only names and prefixes keep them apart. The isolation workflow runs the whole suite with
 * ISOLATION_DB=mysql ISOLATION_REDIS=1 ISOLATION_S3=1; without them these tests prove the default drivers instead or
 * are skipped, and the first test makes sure a run never silently falls back.
 */
class DriversIsolationTest extends IsolationTestCase
{
    public function test_the_run_really_uses_the_drivers_it_was_asked_for(): void
    {
        $this->assertSame(static::driver('mysql') ? 'mysql' : 'sqlite', $this->inFirm('a', fn () => DB::connection()->getDriverName()));
        $this->assertNotSame($this->inFirm('a', fn () => DB::getDatabaseName()), $this->inFirm('b', fn () => DB::getDatabaseName()));
        $this->assertSame(static::driver('redis') ? 'redis' : 'file', config('session.driver'));
        if (static::driver('redis')) {
            $this->assertInstanceOf(RedisStore::class, Cache::getStore());
            $this->assertSame('PONG', strtoupper((string) (app('redis')->connection()->ping() === true ? 'PONG' : app('redis')->connection()->ping())));
        }
        if (static::driver('s3')) {
            $this->assertNotEmpty(config('filesystems.disks.s3.bucket'), 'ISOLATION_S3 needs AWS_BUCKET (and AWS_ENDPOINT for MinIO)');
        }
    }

    public function test_one_redis_holds_both_firms_and_every_key_carries_its_own_firms_prefix(): void
    {
        if (! static::driver('redis')) {
            $this->markTestSkipped('ISOLATION_REDIS is not set.');
        }
        $this->inFirm('a', fn () => Cache::put('same-key', $this->canary('a'), 600));
        $this->inFirm('b', fn () => Cache::put('same-key', $this->canary('b'), 600));
        $this->inFirm('a', fn () => Cache::lock('same-lock', 30)->get());

        $prefixes = ['a' => $this->inFirm('a', fn () => (string) config('cache.prefix')), 'b' => $this->inFirm('b', fn () => (string) config('cache.prefix'))];
        $redis = app('redis')->connection('cache');
        $global = (string) config('database.redis.options.prefix');
        $seen = ['a' => 0, 'b' => 0];
        foreach ($redis->keys('*') as $key) {
            $key = str_starts_with($key, $global) ? substr($key, strlen($global)) : $key;
            $owner = str_starts_with($key, $prefixes['a']) ? 'a' : (str_starts_with($key, $prefixes['b']) ? 'b' : null);
            $this->assertNotNull($owner, "a cache key without a firm prefix: {$key}");
            $seen[$owner]++;
            $other = $owner === 'a' ? 'b' : 'a';
            $this->assertStringNotContainsString($this->canary($other), (string) $redis->get($key), "firm {$other}'s value under firm {$owner}'s key {$key}");
        }
        $this->assertGreaterThanOrEqual(2, $seen['a'], 'the entry and the lock');
        $this->assertGreaterThanOrEqual(1, $seen['b']);

        $this->inFirm('b', fn () => Cache::flush());   // a firm's flush on a shared Redis
        $this->assertSame($this->canary('a'), $this->inFirm('a', fn () => Cache::get('same-key')), 'flushing B emptied A');
    }

    public function test_jobs_on_a_shared_redis_queue_run_in_their_own_firm(): void
    {
        if (! static::driver('redis')) {
            $this->markTestSkipped('ISOLATION_REDIS is not set.');
        }
        config(['queue.connections.iso_redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90], 'queue.default' => 'iso_redis', 'queue.failed.driver' => 'null']);
        $this->app->forgetInstance('queue');
        $this->app->forgetInstance('queue.connection');
        Queue::clearResolvedInstances();
        DriverProbeJob::$ran = [];

        $this->inFirm('a', function () {
            DriverProbeJob::dispatch('from-a');
        });
        $this->inFirm('b', function () {
            DriverProbeJob::dispatch('from-b');
        });
        Artisan::call('queue:work', ['connection' => 'iso_redis', '--queue' => 'default', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--memory' => 4096]);

        $ran = collect(DriverProbeJob::$ran)->keyBy('label');
        $this->assertSame(['a', 'b'], [$ran['from-a']['tenant'], $ran['from-b']['tenant']]);
        $this->assertSame([2, 5], [$ran['from-a']['clients'], $ran['from-b']['clients']]);
        $this->assertStringNotContainsString($this->canary('b'), json_encode($ran['from-a']));
        $this->assertStringNotContainsString($this->canary('a'), json_encode($ran['from-b']));
        $this->assertSame('tenant_unresolved', DB::getDefaultConnection(), 'the worker fails closed after the jobs');
    }

    public function test_one_bucket_holds_both_firms_and_each_firm_reaches_only_its_own_objects(): void
    {
        if (! static::driver('s3')) {
            $this->markTestSkipped('ISOLATION_S3 is not set.');
        }
        foreach ($this->firm as $f) {
            $f['tenant']->forceFill(['files_disk' => 's3'])->save();
        }
        $disk = Storage::disk('s3');
        foreach (['a', 'b'] as $slug) {
            $disk->deleteDirectory("tenants/{$slug}");
        }

        $store = function (string $slug, int $caseId) {
            $r = $this->upload($slug, '/documents', ['title' => "{$slug}-doc", 'case_id' => $caseId], 'file',
                UploadedFile::fake()->createWithContent("{$slug}.pdf", "%PDF-1.4\n".$this->canary($slug)." secret\n"))->assertOk();

            return [(int) $r->json('data.id'), (string) $r->json('data.filepath')];
        };
        [$idA, $pathA] = $store('a', 1);
        [$idB, $pathB] = $store('b', 1);
        [$idB5] = $store('b', 5);
        $this->assertSame($idA, $idB, 'ids deliberately collide');

        $this->assertStringStartsWith('tenants/a/', $pathA);
        $this->assertStringStartsWith('tenants/b/', $pathB);
        $this->assertSame('s3', $this->inFirm('a', fn () => Document::find($idA)->disk));
        foreach (['a' => 1, 'b' => 2] as $slug => $count) {
            $objects = $disk->allFiles("tenants/{$slug}");
            $this->assertCount($count, $objects, "firm {$slug}'s objects in the shared bucket");
            $this->assertSame($this->inFirm($slug, fn () => Document::pluck('filepath')->sort()->values()->all()), collect($objects)->sort()->values()->all());
            foreach ($objects as $o) {
                $this->assertStringNotContainsString($this->canary($slug === 'a' ? 'b' : 'a'), $disk->get($o));
            }
        }

        $this->api('a', 'GET', '/preview?id='.$idB5)->assertNotFound();
        $this->api('a', 'DELETE', "/documents/{$idB5}")->assertNotFound();
        $this->assertTrue($disk->exists($this->inFirm('b', fn () => Document::find($idB5)->filepath)));
        $own = $this->api('a', 'GET', '/preview?id='.$idA)->assertOk();
        $body = $own->baseResponse instanceof StreamedResponse ? $own->streamedContent() : (string) $own->getContent();
        $this->assertStringContainsString($this->canary('a'), $body);
        $this->assertStringNotContainsString($this->canary('b'), $body);
    }

    protected function tearDown(): void
    {
        if (static::driver('s3') && config('filesystems.disks.s3.bucket')) {
            foreach (['a', 'b'] as $slug) {
                Storage::disk('s3')->deleteDirectory("tenants/{$slug}");
            }
        }
        parent::tearDown();
    }
}

class DriverProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<array<string, mixed>> */
    public static array $ran = [];

    public function __construct(public string $label) {}

    public function handle(): void
    {
        self::$ran[] = ['label' => $this->label, 'tenant' => app(TenantContext::class)->key(), 'clients' => Client::count(), 'names' => Client::pluck('name')->all()];
    }
}
