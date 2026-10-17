<?php

namespace App\Jobs;

use App\Queue\BulkWork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-8: makes documents uploaded before full-text search searchable. Queues one ExtractDocumentText per current
 * document version that has no text row yet (DATA-13: queued, chunked, idempotent; runs in its own firm, TEN-3).
 */
class BackfillDocumentText implements ShouldQueue
{
    use BulkWork, Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 3;   // ENG-20: real failures; waiting for the firm's bulk slot does not count

    public function __construct(public int $chunk = 500)
    {
        $this->onBulkLane();   // ENG-20
    }

    public function handle(): int
    {
        if (! config('documents.text.enabled') || ! Schema::hasTable('document_texts')) {
            return 0;
        }
        $queued = 0;
        DB::table('documents')->whereNull('deleted_at')->where('is_current', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('document_texts')->whereColumn('document_texts.document_id', 'documents.id'))
            ->orderBy('id')->select('id')
            ->chunkById($this->chunk, function ($docs) use (&$queued) {
                foreach ($docs as $d) {
                    ExtractDocumentText::dispatch((int) $d->id);
                    $queued++;
                }
            });

        return $queued;
    }
}
