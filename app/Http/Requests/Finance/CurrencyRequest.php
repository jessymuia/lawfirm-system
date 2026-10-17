<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** DATA-14: a currency the firm invoices in. */
class CurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'name' => 'required|string|max:100',
            'minor_units' => 'sometimes|integer|min:0|max:4',
            'symbol' => 'sometimes|nullable|string|max:10',
            'enabled' => 'sometimes|boolean',
        ];
    }
}
