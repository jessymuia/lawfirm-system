<?php

namespace Tests\Feature;

use App\Jobs\BuildFirmExport;
use App\Jobs\SendSmsJob;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * ENG-20: the Redis deployment for real (CI runs a Redis service; skipped where none is reachable): firms stay apart in
 * the cache, the bulk slots hold across workers, and the queue keeps its lanes.
 */
class RedisIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The phpredis extension is not installed.');
        }
        config([
            'database.redis.default.database' => 15, 'database.redis.cache.database' => 14,   // scratch databases, emptied below
            'cache.default' => 'redis', 'queue.default' => 'redis', 'queue.connections.redis.queue' => 'default',
        ]);
        try {
            Redis::connection()->ping();   // tenant-prefix: allow -- test setup, not application code
        } catch (\Throwable) {
            $this->markTestSkipped('No Redis reachable at '.config('database.redis.default.host').'.');
        }
        Redis::connection()->flushdb();         // tenant-prefix: allow -- test setup
        Redis::connection('cache')->flushdb();  // tenant-prefix: allow -- test setup
        app('cache')->forgetDriver();
    }

    public function test_firms_never_see_each_others_cache_or_slots(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->activate('firm-a');
        Cache::put('dashboard', 'A figures', 60);
        $this->assertTrue(Cache::lock('bulk-slot:x:1', 60)->get());

        $ctx->activate('firm-b');
        $this->assertNull(Cache::get('dashboard'), 'firm B cannot read firm A\'s cache');
        $this->assertTrue(Cache::lock('bulk-slot:x:1', 60)->get(), 'nor take its locks');
        Cache::put('dashboard', 'B figures', 60);

        $ctx->activate('firm-a');
        $this->assertSame('A figures', Cache::get('dashboard'));
        $this->assertFalse(Cache::lock('bulk-slot:x:1', 60)->get(), 'a slot held is held for every worker');
    }

    public function test_one_firms_flush_leaves_the_other_firms_keys(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->activate('firm-a');
        Cache::put('dashboard', 'A figures', 60);
        Cache::lock('nightly', 60)->get();
        foreach (range(1, 2500) as $i) {   // more than one SCAN batch
            Cache::put("b-sized-{$i}", $i, 60);
        }
        $ctx->activate('firm-b');
        foreach (range(1, 2500) as $i) {
            Cache::put("many-{$i}", $i, 60);
        }
        $this->assertTrue(Cache::flush());   // cache:clear run for firm B (Laravel's own store would FLUSHDB)

        $this->assertNull(Cache::get('many-1'));
        $this->assertNull(Cache::get('many-2500'));
        $ctx->activate('firm-a');
        $this->assertSame('A figures', Cache::get('dashboard'), 'B\'s flush emptied A\'s cache');
        $this->assertEquals(2500, Cache::get('b-sized-2500'));
        $this->assertFalse(Cache::lock('nightly', 60)->get(), 'A\'s lock survives B\'s flush');
    }

    public function test_the_queue_keeps_interactive_and_bulk_lanes_apart(): void
    {
        SendSmsJob::dispatch('0700000000', 'Hearing tomorrow');
        BuildFirmExport::dispatch(7);
        BuildFirmExport::dispatch(8);

        $this->assertSame([1, 2], [Queue::size('default'), Queue::size('bulk')]);
        $job = Queue::pop('bulk');
        $this->assertSame(BuildFirmExport::class, $job->resolveName());
        $this->assertNotNull($job->retryUntil(), 'bulk work retries until a deadline');
        $job->delete();
        $this->assertSame(1, Queue::size('bulk'));
    }
}
