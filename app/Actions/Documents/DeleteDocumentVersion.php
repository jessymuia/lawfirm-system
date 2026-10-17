<?php

namespace App\Actions\Documents;

use App\Actions\Concerns\Stamps;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ENG-9: delete one version of a document (soft). When it was the current version the latest remaining one becomes
 * current, so a document always has exactly one current version while any is left (FUN-5).
 */
class DeleteDocumentVersion
{
    use Stamps;

    public function handle(Document $document, ?User $actor): void
    {
        DB::transaction(function () use ($document, $actor) {
            $versions = Document::where('document_group_id', $document->document_group_id)->lockForUpdate()->get();
            $wasCurrent = (bool) $document->fresh()?->is_current;
            $this->softDelete($document, $actor);
            if ($wasCurrent) {
                $versions->reject(fn (Document $d) => $d->id === $document->id)->sortByDesc('version')->first()
                    ?->forceFill(['is_current' => true])->save();
            }
        });
    }
}
