<?php

namespace App\Http\Requests\Signup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** PLAT-13: input rules for SignupController::store(). Public endpoint: no authorization beyond the host and the throttle. */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'firm_name' => 'required|string|min:2|max:120',
            'slug' => 'required|string|max:32',
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email:rfc|max:191',
            'password' => ['required', 'string', 'confirmed', 'max:200', Password::min(10)->letters()->numbers()],
            'phone' => 'nullable|string|max:32',
            'currency' => 'nullable|in:'.implode(',', (array) config('billing.currencies')),
            'accept_terms' => 'accepted',
            // a field real people never see or fill; bots that fill every input are turned away quietly
            'website' => 'nullable|max:0',
        ];
    }

    public function messages(): array
    {
        return ['website.max' => 'Please try again.', 'accept_terms.accepted' => 'Please accept the terms of service to continue.'];
    }
}
