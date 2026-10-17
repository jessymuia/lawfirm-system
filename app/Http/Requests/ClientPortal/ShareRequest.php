<?php

namespace App\Http\Requests\ClientPortal;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-10 share one of the matter's documents with its client. */
class ShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['document_id' => 'required|integer|exists:documents,id', 'notify' => 'sometimes|boolean'];
    }
}
