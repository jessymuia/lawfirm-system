<?php

namespace App\Http\Requests\TrustReconciliation;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-12: input rules for TrustReconciliationController::addItem() (a bank item not yet in the ledger). */
class ItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|not_in:0|between:-999999999,999999999',
            'description' => 'required|string|min:5|max:500',
        ];
    }
}
