<?php

namespace App\Http\Requests\Compliance;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ComplianceController::releaseHold(). Authorization is declared on the route (authz:*), not here. */
class ReleaseHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => 'required|string|max:2000'];
    }
}
