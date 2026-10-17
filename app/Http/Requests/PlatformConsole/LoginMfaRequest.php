<?php

namespace App\Http\Requests\PlatformConsole;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformConsoleController::loginMfa(). Authorization is declared on the route (authz:*), not here. */
class LoginMfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['challenge' => 'required|string|max:100', 'code' => 'required|string|max:20'];
    }
}
