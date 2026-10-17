<?php

namespace App\Http\Requests\PlatformBilling;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformBillingController actions that only need a reason (disableAutopay()). Authorization is declared on the route (authz:*), not here. */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => 'required|string|min:5|max:500'];
    }
}
