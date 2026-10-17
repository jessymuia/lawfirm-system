<?php

namespace App\Http\Requests\Compliance;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ComplianceController::placeHold(). Authorization is declared on the route (authz:*), not here. */
class PlaceHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['scope_type' => 'required|in:client,case', 'scope_id' => 'required|integer', 'reason' => 'required|string|max:2000'];
    }
}
