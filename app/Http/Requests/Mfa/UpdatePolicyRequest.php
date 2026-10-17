<?php

namespace App\Http\Requests\Mfa;

use App\Security\Mfa;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for MfaController::updatePolicy(). Authorization is declared on the route (authz:*), not here. */
class UpdatePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['require_mfa' => 'required|boolean'];
    }
}
