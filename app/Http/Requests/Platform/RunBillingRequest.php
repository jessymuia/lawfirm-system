<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: invoice every period that ends on or before a date (at most a year ahead). */
class RunBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['through' => 'nullable|date|before_or_equal:'.now()->addMonths(12)->toDateString()];
    }
}
