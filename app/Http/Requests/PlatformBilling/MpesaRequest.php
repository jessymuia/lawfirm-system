<?php

namespace App\Http\Requests\PlatformBilling;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformBillingController::mpesa(). Authorization is declared on the route (platform.auth:operate). */
class MpesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['phone' => 'required|string|max:20', 'reason' => 'required|string|min:5|max:500'];
    }
}
