<?php

namespace App\Http\Requests\Organisation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FUN-18: input rules for OrganisationController::storeOffice()/updateOffice(). Authorization is on the route. */
class OfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('office');

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('offices', 'name')->ignore($id)->whereNull('deleted_at')],
            'code' => ['nullable', 'string', 'max:16', Rule::unique('offices', 'code')->ignore($id)->whereNull('deleted_at')],
            'city' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:191',
            'is_head_office' => 'sometimes|boolean',
        ];
    }
}
