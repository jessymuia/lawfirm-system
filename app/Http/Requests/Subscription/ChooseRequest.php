<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;

/** PLAT-13: input rules for SubscriptionController::choose(). Authorization is declared on the route (authz:*), not here. */
class ChooseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $paid = array_values(array_diff(\App\Billing\PlanStore::selectable(), ['trial']));

        return ['plan' => 'required|string|in:'.implode(',', $paid)];
    }
}
