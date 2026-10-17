<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for APIController::passwordRecovery(). Authorization is declared on the route (authz:*), not here. */
class PasswordRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => 'required|email',
        ];
    }
}
