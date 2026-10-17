<?php

namespace App\Http\Controllers;

use App\Documents\DocumentSearch;
use App\Http\Requests\DocumentSearch\SearchRequest;
use App\Jobs\ExtractDocumentText;
use App\Models\Cases;
use App\Models\Document;
use Illuminate\Http\JsonResponse;

/** FUN-8: search inside documents, see which of a matter's documents are searchable, and read one again. */
class DocumentSearchController extends Controller
{
    public function __construct(private DocumentSearch $search) {}

    public function search(SearchRequest $r): JsonResponse
    {
        return $this->response(true, 'success', $this->search->search(
            (string) $r->validated('q'), $r->validated('case_id') ? (int) $r->validated('case_id') : null,
            (int) ($r->validated('page') ?? 1), (int) ($r->validated('per_page') ?? 20)), 200);
    }

    public function states(int $case): JsonResponse
    {
        $m = Cases::findOrFail($case);   // matter access applies

        return $this->response(true, 'success', $this->search->states((int) $m->id), 200);
    }

    /** Read a document's text again (after installing OCR, or when it failed). */
    public function reindex(int $document): JsonResponse
    {
        $doc = Document::findOrFail($document);
        ExtractDocumentText::dispatch((int) $doc->id);

        return $this->response(true, 'The document is being read again.', null, 202);
    }
}
