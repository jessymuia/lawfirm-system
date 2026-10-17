<?php

namespace App\Http\Requests\Compliance;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ComplianceController::setRetention(). Authorization is declared on the route (authz:*), not here. */
class SetRetentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['retain_years' => 'required|integer|min:1|max:100', 'auto_purge' => 'sometimes|boolean'];
    }
}
