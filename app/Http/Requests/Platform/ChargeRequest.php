<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: a one-off invoice to a firm. The amount is in minor units. */
class ChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['description' => 'required|string|min:3|max:200', 'amount' => 'required|integer|min:1', 'quantity' => 'nullable|integer|min:1|max:1000', 'reason' => 'required|string|min:5|max:500'];
    }
}
