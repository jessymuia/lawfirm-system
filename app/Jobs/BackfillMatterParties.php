<?php

namespace App\Jobs;

use App\Conflicts\Names;
use App\Queue\BulkWork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-9: copies every existing matter's free-text opposing party into matter_parties, so conflict checks see the
 * firm's history from day one. Queued and chunked (DATA-13), idempotent (a matter that already has its mirrored
 * party is skipped), writes no audit rows. Runs in the firm it was dispatched from (TEN-3).
 */
class BackfillMatterParties implements ShouldQueue
{
    use BulkWork, Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 3;   // ENG-20: real failures; waiting for the firm's bulk slot does not count

    public function __construct(public int $chunk = 500)
    {
        $this->onBulkLane();   // ENG-20
    }

    public function handle(): int
    {
        if (! Schema::hasTable('matter_parties')) {
            return 0;
        }
        $made = 0;
        DB::table('cases')->whereNull('deleted_at')->where('opposing_party', '!=', '')->orderBy('id')
            ->chunkById($this->chunk, function ($cases) use (&$made) {
                $have = DB::table('matter_parties')->whereIn('case_id', $cases->pluck('id'))->where('from_opposing_party', true)->pluck('case_id')->flip();
                $rows = [];
                foreach ($cases as $c) {
                    if (isset($have[$c->id]) || trim((string) $c->opposing_party) === '') {
                        continue;
                    }
                    $rows[] = ['case_id' => $c->id, 'role' => 'adverse', 'name' => trim($c->opposing_party), 'name_normalized' => Names::normalize($c->opposing_party),
                        'from_opposing_party' => true, 'status' => 1, 'archive' => 0, 'created_at' => now(), 'updated_at' => now()];
                }
                if ($rows) {
                    DB::table('matter_parties')->insert($rows);
                    $made += count($rows);
                }
            });

        return $made;
    }
}
