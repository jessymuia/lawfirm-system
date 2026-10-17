<?php

namespace App\Http\Requests\MatterParty;

use App\Models\MatterParty;
use Illuminate\Foundation\Http\FormRequest;

/** FUN-9: input rules for MatterPartyController::store(). Authorization is declared on the route (authz:*), not here. */
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
            'role' => 'required|in:'.implode(',', MatterParty::ROLES),
            'name' => 'required|string|min:2|max:255',
            'identifier' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
