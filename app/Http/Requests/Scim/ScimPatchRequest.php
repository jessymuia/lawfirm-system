<?php

namespace App\Http\Requests\Scim;

use Illuminate\Foundation\Http\FormRequest;

/** SEC-12: a SCIM PATCH (RFC 7644 3.5.2): a list of add/replace/remove operations. */
class ScimPatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'Operations' => 'required|array|min:1|max:50',
            'Operations.*.op' => 'required|string|in:add,replace,remove,Add,Replace,Remove',
            'Operations.*.path' => 'nullable|string|max:200',
            'Operations.*.value' => 'nullable',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            \App\Http\Middleware\ScimTokenAuth::error(400, $validator->errors()->first(), 'invalidSyntax')
        );
    }
}
