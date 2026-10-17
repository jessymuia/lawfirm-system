<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** DATA-14: the firm's base currency (ISO 4217). */
class BaseCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['base_currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/']];
    }
}
