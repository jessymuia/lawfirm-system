<?php

namespace App\Http\Requests\Scim;

use App\Http\Middleware\ScimTokenAuth;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** SEC-12: the SCIM 2.0 user payload (create and replace). PATCH uses ScimPatchRequest. Authorization is the SCIM bearer token (scim.token). */
class ScimUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'userName' => 'required|string|email|max:255',
            'displayName' => 'nullable|string|max:255',
            'name' => 'nullable|array',
            'name.formatted' => 'nullable|string|max:255',
            'name.givenName' => 'nullable|string|max:120',
            'name.familyName' => 'nullable|string|max:120',
            'active' => 'nullable',
            'emails' => 'nullable|array|max:10',
            'phoneNumbers' => 'nullable|array|max:10',
            'externalId' => 'nullable|string|max:255',
        ];
    }

    /** SCIM clients expect the SCIM error body, not Laravel's. */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ScimTokenAuth::error(400, $validator->errors()->first(), 'invalidValue')
        );
    }
}
