<?php

namespace App\Http\Requests\PlatformStatus;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformStatusController::sla(). Authorization is declared on the route (authz:*), not here. */
class SlaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['days' => 'sometimes|integer|min:1|max:'.config('status.max_window_days')];
    }
}
