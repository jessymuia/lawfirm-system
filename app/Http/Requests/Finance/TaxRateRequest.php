<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** DATA-14: a tax rule, as a percentage (16 for 16%). */
class TaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'rate' => 'required|numeric|min:0|max:100',
            'jurisdiction' => 'sometimes|nullable|string|max:60',
            'is_default' => 'sometimes|boolean',
        ];
    }
}
