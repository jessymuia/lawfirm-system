<?php

namespace App\Http\Requests\TrustReconciliation;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-12: input rules for TrustReconciliationController::clear() (tick what the statement shows). */
class ClearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'transaction_ids' => 'required|array|min:1|max:2000',
            'transaction_ids.*' => 'integer',
            'cleared' => 'required|boolean',
        ];
    }
}
