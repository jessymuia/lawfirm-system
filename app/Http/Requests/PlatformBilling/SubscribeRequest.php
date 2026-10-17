<?php

namespace App\Http\Requests\PlatformBilling;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformBillingController::subscribe(). Authorization is declared on the route (authz:*), not here. */
class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['plan' => 'required|string|in:'.implode(',', \App\Billing\PlanStore::selectable()), 'currency' => 'required|in:'.implode(',', config('billing.currencies')), 'provider' => 'sometimes|in:manual,stripe,mpesa',
            'billing_email' => 'nullable|email', 'trial_days' => 'sometimes|integer|min:0|max:90', 'reason' => 'required|string|min:5|max:500'];
    }
}
