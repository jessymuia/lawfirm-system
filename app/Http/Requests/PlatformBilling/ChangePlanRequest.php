<?php

namespace App\Http\Requests\PlatformBilling;

use App\Billing\PlanStore;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformBillingController::changePlan(). Authorization is declared on the route (authz:*), not here. */
class ChangePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['plan' => 'required|string|in:'.implode(',', PlanStore::selectable()), 'reason' => 'required|string|min:5|max:500'];
    }
}
