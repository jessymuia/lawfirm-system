<?php

namespace App\Http\Requests\Cases;

use App\Enums\MatterStatus;
use App\Models\Cases;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for CasesController::transitionStatus(). Authorization is declared on the route (authz:*), not here. */
class TransitionStatusRequest extends FormRequest
{
    use \App\Http\Requests\Concerns\RouteRecord;

    protected string $recordModel = \App\Models\Cases::class;

    protected string $recordParameter = 'id';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lifecycle_status' => ['required', MatterStatus::rule()],
        ];
    }
}
