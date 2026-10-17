<?php

namespace App\Http\Requests\Scim;

use App\Http\Middleware\ScimTokenAuth;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ScimTokenAuth::error(400, $validator->errors()->first(), 'invalidSyntax')
        );
    }
}
