<?php

namespace App\Http\Requests\PlatformStatus;

use App\Status\Incidents;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformStatusController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => 'required|in:'.implode(',', Incidents::STATUSES), 'message' => 'required|string|max:2000'];
    }
}
