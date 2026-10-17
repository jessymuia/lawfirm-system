<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: create or edit a billing plan. The code is only given when creating one. */
class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:300',
            'prices' => 'required|array',
            'features' => 'sometimes|array',
            'features.*' => 'string|in:'.implode(',', (array) config('entitlements.features')),
            'limits' => 'sometimes|array',
            'limits.users' => 'nullable|integer|min:0|max:100000',
            'limits.storage_gb' => 'nullable|numeric|min:0|max:1000000',
            'limits.audit_online_months' => 'nullable|integer|min:0|max:600',
            'reason' => 'required|string|min:5|max:500',
        ];
        foreach ((array) config('billing.currencies') as $currency) {
            $rules["prices.{$currency}"] = 'nullable|integer|min:0|max:100000000000';
        }
        if ($this->route('code') === null) {
            $rules['code'] = ['required', 'regex:/^[a-z][a-z0-9_]{1,31}$/'];
        }

        return $rules;
    }
}
