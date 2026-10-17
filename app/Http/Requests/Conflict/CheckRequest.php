<?php

namespace App\Http\Requests\Conflict;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-9: input rules for ConflictController::check(). Authorization is declared on the route (authz:*), not here. */
class CheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'purpose' => 'required|in:client,matter,search',
            'names' => 'required|array|min:1|max:20',
            'names.*.name' => 'required|string|min:2|max:255',
            'names.*.role' => 'required|in:client,adverse,co_party,related,opposing_counsel',
            'phone' => 'nullable|string|max:32',
            'identifier' => 'nullable|string|max:64',
            'exclude_case_id' => 'nullable|integer',
        ];
    }
}
