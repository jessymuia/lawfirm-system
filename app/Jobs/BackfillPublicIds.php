<?php

namespace App\Jobs;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Hearing;
use App\Queue\BulkWork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-9: gives every pre-existing client, matter, hearing, invoice, payment and document a public_id. Queued and chunked (DATA-13: backfills are
 * jobs, never migrations), idempotent (only rows still null are touched, each guarded by `whereNull`), and it
 * bypasses model events so it writes no audit rows and leaves updated_at alone. Runs in the tenant it was dispatched
 * from (TEN-3 carries the firm on the job).
 */
class BackfillPublicIds implements ShouldQueue
{
    use BulkWork, Dispatchable, InteractsWithQueue, Queueable;

    public const MODELS = [Client::class, Cases::class, Hearing::class, Invoice::class, Payment::class, Document::class];

    public int $maxExceptions = 3;   // ENG-20: real failures; waiting for the firm's bulk slot does not count

    public function __construct(public int $chunk = 1000)
    {
        $this->onBulkLane();   // ENG-20
    }

    /** @return array<string,int> table => rows filled */
    public function handle(): array
    {
        $filled = [];
        foreach (self::MODELS as $model) {
            $table = (new $model)->getTable();
            $filled[$table] = 0;
            if (! Schema::hasColumn($table, 'public_id')) {
                continue;
            }
            DB::table($table)->whereNull('public_id')->orderBy('id')->chunkById($this->chunk, function ($rows) use ($table, $model, &$filled) {
                foreach ($rows as $row) {
                    $filled[$table] += DB::table($table)->where('id', $row->id)->whereNull('public_id')->update(['public_id' => $model::newPublicId()]);
                }
            });
        }

        return $filled;
    }
}
