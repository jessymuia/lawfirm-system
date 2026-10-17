<?php

namespace App\Http\Requests\PlatformConsole;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformConsoleController::offboard(). Authorization is declared on the route (authz:*), not here. */
class OffboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['confirm' => 'required|string', 'reason' => 'required|string|min:5|max:500', 'retention_days' => 'nullable|integer|min:0|max:3650'];
    }
}
