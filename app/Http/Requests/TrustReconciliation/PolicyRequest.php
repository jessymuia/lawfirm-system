<?php

namespace App\Http\Requests\TrustReconciliation;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-12: input rules for TrustReconciliationController::savePolicy() (an evergreen retainer). */
class PolicyRequest extends FormRequest
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
            'minimum_balance' => 'required|numeric|min:0|max:999999999',
            'target_balance' => 'required|numeric|gte:minimum_balance|max:999999999',
        ];
    }
}
