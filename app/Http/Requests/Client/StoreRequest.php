<?php

namespace App\Http\Requests\Client;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for ClientController::store(). Authorization is declared on the route (authz:*), not here. */
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
            'ebilling_client_id' => ['nullable', 'string', 'max:64', 'not_regex:/[|\[\]]/'],   // FUN-13
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20|unique:clients,phone_number,NULL,id,deleted_at,NULL',
            'extra_phone_number' => 'nullable|string|max:20',
            'address' => 'required|string|max:255',
            'advocate' => 'required|integer|exists:users,id',
            'conflict_check_id' => 'nullable|integer',   // FUN-9
        ];
    }
}
