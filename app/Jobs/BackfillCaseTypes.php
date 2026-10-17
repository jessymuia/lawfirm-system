<?php

namespace App\Jobs;

use App\Matters\CaseTypes;
use App\Queue\BulkWork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-12: gives every existing matter its case_type_id from its free-text case_type (types that differ only in
 * letter case or spacing become one). Queued and chunked (DATA-13), idempotent (matters that have an id are skipped),
 * writes no audit rows. Runs in the firm it was dispatched from (TEN-3).
 */
class BackfillCaseTypes implements ShouldQueue
{
    use BulkWork, Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 3;   // ENG-20: real failures; waiting for the firm's bulk slot does not count

    public function __construct(public int $chunk = 500)
    {
        $this->onBulkLane();   // ENG-20
    }

    public function handle(CaseTypes $types): int
    {
        if (! Schema::hasColumn('cases', 'case_type_id')) {
            return 0;
        }
        $filled = 0;
        DB::table('cases')->whereNull('case_type_id')->whereNotNull('case_type')->where('case_type', '!=', '')->orderBy('id')
            ->chunkById($this->chunk, function ($rows) use ($types, &$filled) {
                foreach ($rows as $row) {
                    $type = $types->resolve((string) $row->case_type);
                    if ($type) {
                        DB::table('cases')->where('id', $row->id)->update(['case_type_id' => $type->id]);
                        $filled++;
                    }
                }
            });

        return $filled;
    }
}
