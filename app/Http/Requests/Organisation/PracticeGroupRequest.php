<?php

namespace App\Http\Requests\Organisation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FUN-18: input rules for OrganisationController::storeGroup()/updateGroup(). Authorization is on the route. */
class PracticeGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('group');

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('practice_groups', 'name')->ignore($id)->whereNull('deleted_at')],
            'code' => ['nullable', 'string', 'max:16', Rule::unique('practice_groups', 'code')->ignore($id)->whereNull('deleted_at')],
            'description' => 'nullable|string|max:500',
            'head_user_id' => 'nullable|integer|exists:users,id',
        ];
    }
}
