<?php

namespace App\Http\Requests\Scim;

use Illuminate\Foundation\Http\FormRequest;

/** SEC-12: a security administrator issues a provisioning token. Authorization is declared on the route (manage-security-settings). */
class CreateTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => 'required|string|max:100', 'default_role_id' => 'nullable|integer|exists:roles,id'];
    }
}
