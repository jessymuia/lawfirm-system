<?php

namespace App\Http\Requests\Mfa;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for MfaController::enable(). Authorization is declared on the route (authz:*), not here. */
class EnableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => 'required|string|max:20'];
    }
}
