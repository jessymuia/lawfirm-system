<?php

namespace App\Http\Requests\PlatformConsole;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformConsoleController::provision(). Authorization is declared on the route (authz:*), not here. */
class ProvisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,62}$/'],
            'name' => 'required|string|max:255',
            'admin_email' => 'required|email',
            'admin_name' => 'nullable|string|max:255',
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'admin_phone' => 'nullable|string|max:32',
            // the initial plan is chosen from the plan catalogue (and is subscribed, not just labelled); blank = the free trial
            'plan' => ['nullable', 'string', 'max:32', \Illuminate\Validation\Rule::in(\App\Billing\PlanStore::selectable())],
            'currency' => ['nullable', \Illuminate\Validation\Rule::in((array) config('billing.currencies'))],
            'trial_days' => 'nullable|integer|min:0|max:365',
            'domain' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:32',
            'cell' => 'nullable|string|max:32',   // TEN-4: omit for automatic placement
        ];
    }
}
