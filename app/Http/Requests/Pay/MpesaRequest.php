<?php

namespace App\Http\Requests\Pay;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-11: input rules for PayController::mpesa(). Public endpoint: the payment link is the authority. */
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
