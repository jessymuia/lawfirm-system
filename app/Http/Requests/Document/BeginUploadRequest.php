<?php

namespace App\Http\Requests\Document;

use App\Documents\DirectUploads;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-22: a direct upload is announced before it is sent: what, how big, for which matter. */
class BeginUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'case_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'filename' => 'required|string|max:255',
            'mimetype' => 'required|string|in:'.implode(',', StoreRequest::ALLOWED_MIME_TYPES),
            'size' => 'required|integer|min:1|max:'.DirectUploads::maxBytes(),
            'document_group_id' => 'nullable|integer|exists:documents,document_group_id',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['size.max' => 'The file is larger than '.intdiv(DirectUploads::maxBytes(), 1024 * 1024).' MB.'];
    }
}
