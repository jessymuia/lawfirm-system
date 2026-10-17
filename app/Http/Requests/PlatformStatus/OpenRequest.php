<?php

namespace App\Http\Requests\PlatformStatus;

use App\Status\Incidents;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformStatusController::open(). Authorization is declared on the route (authz:*), not here. */
class OpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:160', 'impact' => 'required|in:'.implode(',', Incidents::IMPACTS), 'message' => 'required|string|max:2000',
            'components' => 'sometimes|array', 'components.*' => 'string', 'status' => 'sometimes|in:'.implode(',', Incidents::STATUSES),
            'starts_at' => 'sometimes|date', 'ends_at' => 'sometimes|date',
        ];
    }
}
