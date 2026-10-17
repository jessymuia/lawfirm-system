<?php

namespace App\Http\Requests\Compliance;

use App\Compliance\Dsar;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ComplianceController::verifyDsar(). Authorization is declared on the route (authz:*), not here. */
class VerifyDsarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['method' => 'required|in:'.implode(',', Dsar::METHODS), 'note' => 'required|string|max:2000'];
    }
}
