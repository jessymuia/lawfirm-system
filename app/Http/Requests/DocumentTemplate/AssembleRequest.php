<?php

namespace App\Http\Requests\DocumentTemplate;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-14 create a matter document from a template, with the answers to its questions. */
class AssembleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template_id' => 'required|integer',
            'title' => 'nullable|string|max:255',
            'answers' => 'nullable|array|max:100',
            'answers.*' => 'nullable|string|max:5000',
        ];
    }
}
