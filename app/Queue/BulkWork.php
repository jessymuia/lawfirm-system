<?php

namespace App\Queue;

/**
 * ENG-20: heavy work. Goes to the firm's bulk lane, runs under its fair share (FirmFairShare), and, because waiting for
 * a slot puts it back on the queue, keeps trying until a deadline instead of a number of attempts; real failures are
 * still limited by the job's `$maxExceptions`.
 */
trait BulkWork
{
    /** Call from the constructor. */
    protected function onBulkLane(): void
    {
        $this->onQueue(Lanes::bulk());
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new FirmFairShare];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours((int) config('queue.bulk.retry_hours', 24));
    }
}
