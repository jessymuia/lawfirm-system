<?php

namespace App\Http\Requests\PaymentLink;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-11: input rules for PaymentLinkController::store(). Authorization is on the route. */
class SendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'days' => 'nullable|integer|min:1|max:90',
            'send_sms' => 'sometimes|boolean',
        ];
    }
}
