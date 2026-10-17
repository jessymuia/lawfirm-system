<?php

namespace App\Queue;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * ENG-20: a firm runs at most `queue.bulk.per_firm` heavy jobs at once, however many it queued and however many workers
 * there are. A job that finds its firm's slots taken goes back on the queue for a while, so the workers serve other
 * firms in the meantime: one firm's export of a million documents cannot starve its cell.
 *
 * The slots are cache locks (under the firm's cache prefix, TEN-6), so this works on any lock-capable cache store;
 * with Redis it holds across every worker host.
 */
class FirmFairShare
{
    public function handle(object $job, Closure $next): void
    {
        $slots = max(1, (int) config('queue.bulk.per_firm', 2));
        $firm = app(TenantContext::class)->key();
        for ($i = 1; $i <= $slots; $i++) {
            $lock = Cache::lock("bulk-slot:{$firm}:{$i}", (int) config('queue.bulk.slot_seconds', 3900));
            if ($lock->get()) {
                try {
                    $next($job);
                } finally {
                    $lock->release();
                }

                return;
            }
        }
        $job->release((int) config('queue.bulk.retry_after', 30));   // BulkWork jobs retry until a deadline, not N times
    }
}
