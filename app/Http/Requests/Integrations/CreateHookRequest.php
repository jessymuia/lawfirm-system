<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for IntegrationsController::createHook(). Authorization is declared on the route (authz:*), not here. */
class CreateHookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['url' => 'required|url|max:2000', 'events' => 'required|array|min:1', 'events.*' => 'string', 'description' => 'nullable|string|max:255'];
    }
}
