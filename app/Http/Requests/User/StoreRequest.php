<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for UserController::store(). Authorization is declared on the route (authz:*), not here. */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'timekeeper_class' => 'nullable|string|in:'.implode(',', array_keys(\App\EBilling\Utbms::TIMEKEEPER_CLASSES)),   // FUN-13
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20',
            'email' => 'required|email|max:255|unique:users,email',
            'department' => 'required|string|max:255',
            'hire_date' => 'required|date_format:d/m/Y',
            'role' => 'nullable',
            'office_id' => 'nullable|integer|exists:offices,id,deleted_at,NULL',               // FUN-18
            'practice_group_id' => 'nullable|integer|exists:practice_groups,id,deleted_at,NULL',
        ];
    }
}
