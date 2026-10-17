<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\Document\StoreRequest;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-10 a client sending the firm a document; the same file rules as staff uploads. */
class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimetypes:'.implode(',', StoreRequest::ALLOWED_MIME_TYPES).'|max:'.StoreRequest::MAX_FILE_SIZE_KB,
            'title' => 'required|string|max:255',
        ];
    }
}
