<?php

namespace App\Http\Requests\DocumentSearch;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-8 search inside documents, firm-wide or within one matter. */
class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => 'required|string|min:2|max:200',
            'case_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1|max:500',
            'per_page' => 'nullable|integer|min:1|max:50',
        ];
    }
}
