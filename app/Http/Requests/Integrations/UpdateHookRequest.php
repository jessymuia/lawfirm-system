<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for IntegrationsController::updateHook(). Authorization is declared on the route (authz:*), not here. */
class UpdateHookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['url' => 'sometimes|url|max:2000', 'events' => 'sometimes|array|min:1', 'events.*' => 'string', 'description' => 'sometimes|nullable|string|max:255', 'active' => 'sometimes|boolean'];
    }
}
