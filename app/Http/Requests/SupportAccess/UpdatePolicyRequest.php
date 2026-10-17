<?php

namespace App\Http\Requests\SupportAccess;

use App\SupportAccess\SupportPolicy;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for SupportAccessController::updatePolicy(). Authorization is declared on the route (authz:*), not here. */
class UpdatePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => 'sometimes|boolean', 'require_approval' => 'sometimes|boolean', 'allow_write' => 'sometimes|boolean',
            'max_minutes' => 'sometimes|integer|min:5|max:'.SupportPolicy::MAX_MINUTES_CEILING,
        ];
    }
}
