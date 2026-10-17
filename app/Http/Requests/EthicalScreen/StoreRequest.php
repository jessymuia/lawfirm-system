<?php

namespace App\Http\Requests\EthicalScreen;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for EthicalScreenController::store(). Authorization is declared on the route (authz:*), not here. */
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
            'user_id' => 'required|integer|exists:users,id',
            'case_id' => 'nullable|integer|exists:cases,id|required_without:client_id',
            'client_id' => 'nullable|integer|exists:clients,id|required_without:case_id|prohibits:case_id',
            'reason' => 'required|string|min:5|max:1000',
        ];
    }
}
