<?php

namespace App\Http\Requests\PaymentLink;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: staff-started M-Pesa prompt for an invoice. */
class MpesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['phone' => 'required|string|max:20'];
    }
}
