<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for IntegrationsController::createKey(). Authorization is declared on the route (authz:*), not here. */
class CreateKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120', 'scopes' => 'required|array|min:1', 'scopes.*' => 'in:'.implode(',', array_keys(config('public_api.scopes'))),
            'user_id' => 'sometimes|integer', 'rate_limit' => 'nullable|integer|min:1|max:'.config('public_api.max_rate_limit'), 'expires_at' => 'nullable|date|after:now',
        ];
    }
}
