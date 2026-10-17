<?php

namespace App\Compliance\RecordClasses;

use App\Compliance\LegalHoldException;
use App\Compliance\LegalHolds;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * SEC-13: documents of closed or settled matters. Disposal deletes the file's bytes and its extracted text (FUN-8) and
 * soft-deletes the row, which stays as a stub so references and history still resolve.
 */
class CaseDocuments implements RecordClass
{
    use ClosedMatters;

    public function __construct(private LegalHolds $holds) {}

    public function type(): string
    {
        return 'document';
    }

    public function due(string $cutoff, array $heldCaseIds): array
    {
        $base = Document::withTrashed()->withoutGlobalScopes()
            ->whereNotIn('documents.id', DB::table('retention_disposals')->whereNotNull('document_id')->select('document_id'))
            ->whereIn('case_id', $this->endedBy($cutoff));

        return ['held' => (clone $base)->whereIn('case_id', $heldCaseIds)->count(),
            'due' => (clone $base)->when($heldCaseIds, fn ($q) => $q->whereNotIn('case_id', $heldCaseIds))->orderBy('id')->pluck('id')];
    }

    public function dispose(int $id, int $years, ?int $actor): array
    {
        $doc = Document::withTrashed()->withoutGlobalScopes()->findOrFail($id);
        if ($this->holds->caseHeld((int) $doc->case_id)) {   // re-checked right before acting
            throw new LegalHoldException('held');
        }
        $deleted = false;
        if ($doc->full_path) {
            $storage = Storage::disk($doc->disk ?: 'local');
            $deleted = $storage->exists($doc->full_path) ? (bool) $storage->delete($doc->full_path) : true;   // already gone counts as gone
            if ($storage->exists($doc->full_path)) {
                throw new \RuntimeException("File for document {$doc->id} could not be removed.");
            }
        }
        DB::transaction(function () use ($doc, $years, $actor, $deleted) {
            DB::table('retention_disposals')->insert([
                'document_id' => $doc->id, 'record_type' => $this->type(), 'record_id' => $doc->id, 'case_id' => $doc->case_id,
                'record_class' => 'case_documents', 'retain_years' => $years, 'file_deleted' => $deleted, 'disposed_by' => $actor,
                'disposed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (Schema::hasTable('document_texts')) {
                DB::table('document_texts')->where('document_id', $doc->id)->delete();   // the searchable copy of its contents
            }
            if (! $doc->trashed()) {
                $doc->delete();   // goes through the legal-hold guard as well
            }
        });

        return ['title' => $doc->title, 'file_deleted' => $deleted];
    }
}
