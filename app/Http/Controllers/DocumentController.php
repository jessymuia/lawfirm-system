<?php

namespace App\Http\Controllers;

use App\Actions\Documents\DeleteDocumentVersion;
use App\Actions\Documents\StoreDocument;
use App\Constants\ModulePermissions;
use App\Http\Requests\Document\StoreRequest;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    // Upload limits (allowed types, size ceiling) live with the rules: App\Http\Requests\Document\StoreRequest (ENG-8).

    /**
     * Store a newly created resource in storage.
     *
     * If `document_group_id` is supplied and belongs to an existing,
     * accessible document, this is stored as a new *version* of that
     * document rather than a new, unrelated document: the previous
     * current version is flagged `is_current = false` and the new upload
     * becomes current at `version + 1`.
     *
     * @return Response
     */
    public function store(StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_DOCUMENTS)) {
            abort(403);
        }

        $document = app(StoreDocument::class)->handle((int) $request->input('case_id'), $request->file('file'),
            ['title' => $request->input('title'), 'document_group_id' => $request->filled('document_group_id') ? (int) $request->document_group_id : null], Auth::user());

        return $this->response(true, 'success', $document, 200);
    }

    /**
     * Display the specified resource.
     *
     * Returns only the current version of each document for the case by
     * default; pass `?all_versions=1` to include every version.
     */
    public function show($id): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_DOCUMENTS)) {
            abort(403);
        }

        $query = Document::where('case_id', $id);

        if (! request()->boolean('all_versions')) {
            $query->where('is_current', true);
        }

        // UI-35: converted to the shared paginatedOrFull() helper so this
        // endpoint works with the shared DataTable component like every
        // other list in the app — same response shape, same page/sort/
        // search query params, no change to the case_id-scoping this
        // endpoint already did via the $id path parameter.
        $documents = $this->paginatedOrFull(
            request(),
            $query->orderBy('id', 'desc'),
            [$this, 'formatDocumentRow'],
            15,
            ['title'],
            ['title', 'filename']
        );

        return $this->response(true, 'success', $documents, 200);
    }

    /**
     * ENG-4: shared row formatter (entity shapes are API Resources, app/Http/Resources/V1, ENG-10).
     */
    public function formatDocumentRow(Document $row): array
    {
        return [
            'id' => $row->id,
            'document_group_id' => $row->document_group_id,
            'title' => $row->title,
            'filename' => $row->filename,
            'full_path' => $row->full_path,
            'version' => $row->version,
            'is_current' => $row->is_current,
            'filesize' => $row->filesize,
        ];
    }

    /**
     * All versions of a single document, oldest first.
     */
    public function versions($groupId): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_DOCUMENTS)) {
            abort(403);
        }

        $versions = Document::where('document_group_id', $groupId)
            ->orderBy('version')
            ->get(['id', 'version', 'is_current', 'filename', 'filesize', 'created_by', 'created_at']);

        return $this->response(true, 'success', $versions, 200);
    }

    /**
     * Soft-delete a single version. If the deleted version was the current
     * one, the next most recent remaining version (if any) is promoted to
     * current, so the document group always has at most one current
     * version and callers of show()/preview() never silently see nothing.
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_DOCUMENTS)) {
            abort(403);
        }

        app(DeleteDocumentVersion::class)->handle(Document::findOrFail($id), Auth::user());

        return $this->response(true, 'success', null, 200);
    }
}
