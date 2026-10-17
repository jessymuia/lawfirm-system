<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** DATA-8: the firm's invoice, receipt and trust-ledger number format. */
class NumberingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_year' => ['required', 'boolean'],
            'prefixes' => ['sometimes', 'array'],
            'prefixes.invoice' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{1,8}$/'],
            'prefixes.receipt' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{1,8}$/'],
            'prefixes.trust' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{1,8}$/'],
        ];
    }
}
