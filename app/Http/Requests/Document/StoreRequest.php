<?php

namespace App\Http\Requests\Document;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for DocumentController::store(). Authorization is declared on the route (authz:*), not here. */
class StoreRequest extends FormRequest
{
    // Broadened from PDF-only. Legal practice documents are routinely
    // Word/Excel and scanned images, not just PDFs.
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/png',
        'image/jpeg',
        'text/plain',
    ];

    // 25MB — up from 10MB, still a deliberate ceiling rather than
    // unlimited, since large files land straight in $10-billed-per-GB
    // cloud storage.
    public const MAX_FILE_SIZE_KB = 25000;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => 'required|mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES).'|max:'.self::MAX_FILE_SIZE_KB,
            'title' => 'required|string|max:255',
            'case_id' => 'required|integer|exists:cases,id',
            'document_group_id' => 'nullable|integer|exists:documents,document_group_id',
        ];
    }
}
