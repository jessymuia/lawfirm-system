<?php

namespace App\Jobs;

use App\Search\SearchIndex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** ENG-19: bring a few records of the firm up to date in its search index. */
class IndexForSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> seconds: the engine may be briefly away */
    public array $backoff = [10, 30, 60, 300];

    /** @param list<int> $ids */
    public function __construct(public string $entity, public array $ids) {}

    public function handle(SearchIndex $index): void
    {
        $index->sync($this->entity, $this->ids);
    }
}
