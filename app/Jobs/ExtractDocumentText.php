<?php

namespace App\Jobs;

use App\Documents\TextExtractor;
use App\Models\Document;
use App\Queue\BulkWork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * FUN-8: reads one document's text (or OCRs it) into document_texts, for full-text search. Queued after each upload;
 * runs in the firm it was dispatched from (TEN-3). A file that cannot be read is recorded as such, never retried forever.
 */
class ExtractDocumentText implements ShouldQueue
{
    use BulkWork, Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 2;   // ENG-20: real failures; waiting for the firm's bulk slot does not count

    public int $timeout = 900;

    public function __construct(public int $documentId)
    {
        $this->onBulkLane();   // ENG-20
    }

    public function handle(TextExtractor $extractor): void
    {
        if (! config('documents.text.enabled') || ! Schema::hasTable('document_texts')) {
            return;
        }
        $doc = Document::withoutGlobalScopes()->whereNull('deleted_at')->find($this->documentId);
        if (! $doc) {
            return;
        }

        $disk = Storage::disk($doc->disk ?: 'local');
        $tmp = null;
        try {
            if (! $disk->exists($doc->full_path)) {
                $result = ['state' => 'failed', 'method' => null, 'content' => null, 'error' => 'The file is missing from storage.'];
            } else {
                // tools need a local path; remote disks (S3) are copied to a temporary file first
                $path = $doc->disk !== 's3' ? $disk->path($doc->full_path) : null;
                if (! $path || ! is_file($path)) {
                    $tmp = tempnam(sys_get_temp_dir(), 'doctext');
                    file_put_contents($tmp, $disk->readStream($doc->full_path));
                    $path = $tmp;
                }
                $result = $extractor->extract($path, (string) $doc->mimetype, (string) $doc->extension);
            }
        } finally {
            $tmp && @unlink($tmp);
        }

        DB::table('document_texts')->updateOrInsert(['document_id' => $doc->id], [
            'state' => $result['state'], 'method' => $result['method'], 'content' => $result['content'],
            'chars' => $result['content'] !== null ? mb_strlen($result['content']) : 0, 'error' => $result['error'],
            'extracted_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        \App\Search\SearchIndex::queue('documents', [(int) $doc->id]);   // ENG-19: its words are searchable now
    }
}
