<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** DATA-14: one unit of `currency` is worth `rate` units of the base currency from `effective_on`. */
class ExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'rate' => 'required|numeric|gt:0|max:9999999999',
            'effective_on' => 'required|date_format:Y-m-d',
            'source' => 'sometimes|string|max:30',
        ];
    }
}
