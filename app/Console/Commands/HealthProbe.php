<?php

namespace App\Console\Commands;

use App\Status\Health;
use Illuminate\Console\Command;

/** PLAT-12: record one health probe per component (history for the status page and measured availability). Scheduled every minute. */
class HealthProbe extends Command
{
    protected $signature = 'health:probe';

    protected $description = 'Probe database, cache, storage, queue (and firm databases) and record the result';

    public function handle(Health $health): int
    {
        $r = $health->probe();
        foreach ($r as $c => [$status, $ms, $detail]) {
            $this->line(sprintf('%-18s %-12s %4d ms %s', $c, $status, $ms, $detail));
        }
        if (now()->minute === 0) {
            $health->prune();
        }

        return collect($r)->contains(fn ($x) => $x[0] === Health::DOWN) ? self::FAILURE : self::SUCCESS;
    }
}
