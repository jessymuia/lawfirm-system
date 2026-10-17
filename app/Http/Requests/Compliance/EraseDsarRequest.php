<?php

namespace App\Http\Requests\Compliance;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ComplianceController::eraseDsar(). Authorization is declared on the route (authz:*), not here. */
class EraseDsarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['confirm' => 'required|accepted'];
    }
}
