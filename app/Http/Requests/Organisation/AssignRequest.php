<?php

namespace App\Http\Requests\Organisation;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-18: input rules for OrganisationController::assign() (move people into an office / practice group). */
class AssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_ids' => 'required|array|min:1|max:500',
            'user_ids.*' => 'integer|exists:users,id',
            'office_id' => 'nullable|integer|exists:offices,id',
            'practice_group_id' => 'nullable|integer|exists:practice_groups,id',
            'apply_to_open_matters' => 'sometimes|boolean',
        ];
    }
}
