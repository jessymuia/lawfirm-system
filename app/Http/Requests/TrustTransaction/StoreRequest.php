<?php

namespace App\Http\Requests\TrustTransaction;

use App\Enums\TrustEntryType;
use App\Models\TrustTransaction;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for TrustTransactionController::store(). Authorization is declared on the route (authz:*), not here. */
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
            'client_id' => 'required|integer|exists:clients,id',
            'case_id' => 'nullable|integer|exists:cases,id',
            'type' => ['required', TrustEntryType::rule()],
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'reference_number' => 'nullable|string|max:255',
        ];
    }
}
