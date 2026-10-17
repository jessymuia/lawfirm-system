<?php

namespace App\Http\Requests\TrustReconciliation;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-12: input rules for TrustReconciliationController::signOff(). */
class SignOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['exception_note' => 'nullable|string|max:2000'];
    }
}
