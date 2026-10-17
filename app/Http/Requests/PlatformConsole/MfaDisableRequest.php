<?php

namespace App\Http\Requests\PlatformConsole;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformConsoleController::mfaDisable(). Authorization is declared on the route (authz:*), not here. */
class MfaDisableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => 'required|string', 'code' => 'required|string|max:20'];
    }
}
