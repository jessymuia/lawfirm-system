<?php

namespace App\Http\Controllers;

use App\Documents\DirectUploads;
use App\Http\Requests\Document\BeginUploadRequest;
use App\Models\Cases;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** ENG-22: documents uploaded straight to the firm's bucket. See docs/ops/DIRECT_UPLOADS.md. */
class DocumentUploadController extends Controller
{
    public function __construct(private DirectUploads $uploads) {}

    /** Reserve the upload and answer the bucket form to POST the file with. 409 direct_uploads_unavailable: upload through POST /documents. */
    public function begin(BeginUploadRequest $r): JsonResponse
    {
        $case = Cases::findOrFail($r->validated('case_id'));   // matter access applies
        $d = $r->validated();

        return $this->response(true, 'success', $this->uploads->begin(Auth::user(), (int) $case->id, $d['title'], $d['filename'], $d['mimetype'],
            (int) $d['size'], isset($d['document_group_id']) ? (int) $d['document_group_id'] : null), 201);
    }

    /** The file has been sent: check it and file it on the matter. */
    public function complete(string $upload): JsonResponse
    {
        return $this->response(true, 'success', $this->uploads->complete($upload, Auth::user()), 201);
    }
}
