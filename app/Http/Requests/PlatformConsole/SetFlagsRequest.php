<?php

namespace App\Http\Requests\PlatformConsole;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformConsoleController::setFlags(). Authorization is declared on the route (authz:*), not here. */
class SetFlagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['flags' => 'required|array|min:1', 'flags.*' => 'nullable|boolean', 'reason' => 'required|string|min:5|max:500'];
    }
}
