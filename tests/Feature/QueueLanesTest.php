<?php

namespace Tests\Feature;

use App\Jobs\BackfillDocumentText;
use App\Jobs\BackfillMatterParties;
use App\Jobs\BackfillPublicIds;
use App\Jobs\BuildFirmExport;
use App\Jobs\ExtractDocumentText;
use App\Jobs\SendSmsJob;
use App\Queue\BulkWork;
use App\Queue\FirmFairShare;
use App\Queue\Lanes;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** ENG-20: heavy work has its own lane, and each firm gets a fair share of the workers. */
class QueueLanesTest extends TestCase
{
    private const BULK = [BuildFirmExport::class, BackfillDocumentText::class, BackfillMatterParties::class, BackfillPublicIds::class, ExtractDocumentText::class];

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'cache.default' => 'array']);
    }

    public function test_heavy_work_goes_to_the_bulk_lane_next_to_the_firms_lane(): void
    {
        Queue::fake();
        BuildFirmExport::dispatch(1);
        ExtractDocumentText::dispatch(1);
        SendSmsJob::dispatch('0700000000', 'Hearing tomorrow');
        Queue::assertPushedOn('bulk', BuildFirmExport::class);
        Queue::assertPushedOn('bulk', ExtractDocumentText::class);
        Queue::assertPushed(SendSmsJob::class, fn ($j) => $j->queue === null);   // the connection's own lane: interactive

        // a firm on a cell with its own lane (TEN-2): its bulk lane sits next to it
        config(['queue.connections.database.queue' => 'eu']);
        $this->assertSame(['eu', 'eu-bulk'], [Lanes::interactive(), Lanes::bulk()]);
        BackfillPublicIds::dispatch();
        Queue::assertPushedOn('eu-bulk', BackfillPublicIds::class);
    }

    public function test_every_bulk_job_waits_for_a_slot_and_retries_until_a_deadline(): void
    {
        foreach (self::BULK as $class) {
            $this->assertContains(BulkWork::class, class_uses($class), $class);
            $job = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $this->assertInstanceOf(FirmFairShare::class, $job->middleware()[0], $class);
            $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $job->retryUntil()->getTimestamp(), 5, $class);
            $this->assertGreaterThan(0, $job->maxExceptions, "{$class}: real failures are still limited");

            // a job must finish before its slot lock expires, and before the queue hands it to another worker
            $timeout = $job->timeout ?? 60;
            $this->assertGreaterThan($timeout, config('queue.bulk.slot_seconds'), $class);
            $this->assertGreaterThan($timeout, config('queue.connections.database.retry_after'), $class);
            $this->assertGreaterThan($timeout, config('queue.connections.redis.retry_after'), $class);
        }
    }

    public function test_a_firm_runs_at_most_its_share_and_other_firms_are_not_held_up(): void
    {
        config(['queue.bulk.per_firm' => 2, 'queue.bulk.retry_after' => 45]);
        $job = new class
        {
            public ?int $releasedFor = null;

            public int $ran = 0;

            public function release(int $delay): void
            {
                $this->releasedFor = $delay;
            }
        };
        $run = fn () => (new FirmFairShare)->handle($job, function ($j) {
            $j->ran++;
        });

        $firm = app(TenantContext::class)->key();
        $busy = [Cache::lock("bulk-slot:{$firm}:1", 60), Cache::lock("bulk-slot:{$firm}:2", 60)];
        $this->assertTrue($busy[0]->get() && $busy[1]->get(), 'two exports of this firm are running');

        $run();
        $this->assertSame([0, 45], [$job->ran, $job->releasedFor], 'a third waits its turn');

        app(TenantContext::class)->activate('other-firm');   // its own cache prefix (TEN-6), its own slots
        $job->releasedFor = null;
        $run();
        $this->assertSame([1, null], [$job->ran, $job->releasedFor], 'another firm is not held up');
        $this->assertTrue(Cache::lock('bulk-slot:other-firm:1', 60)->get(), 'the slot is given back when the job ends');

        app(TenantContext::class)->activate($firm);
        $busy[1]->release();
        $run();
        $this->assertSame(2, $job->ran, 'a freed slot is taken');
    }
}
