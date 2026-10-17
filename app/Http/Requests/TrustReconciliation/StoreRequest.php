<?php

namespace App\Http\Requests\TrustReconciliation;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-12: input rules for TrustReconciliationController::store(). Authorization is on the route. */
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
            'period_end' => 'required|date_format:Y-m-d|before_or_equal:today',
            'bank_balance' => 'required|numeric|between:-999999999999,999999999999',
            'statement_reference' => 'nullable|string|max:120',
        ];
    }
}
