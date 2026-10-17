<?php

namespace App\Http\Requests\PlatformBilling;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformBillingController::pay(). Authorization is declared on the route (authz:*), not here. */
class PayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['amount' => 'required|integer|min:1', 'currency' => 'required|string|size:3', 'reference' => 'required|string|max:120', 'reason' => 'required|string|min:5|max:500'];
    }
}
