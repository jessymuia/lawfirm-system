<?php

namespace App\Http\Requests\Branding;

use Illuminate\Foundation\Http\FormRequest;

/** UI-13: the firm's name as shown, its primary colour, letterhead lines and invoice footer. */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|nullable|string|max:120',
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'letterhead' => 'sometimes|array',
            'letterhead.address' => 'nullable|string|max:300',
            'letterhead.phone' => 'nullable|string|max:60',
            'letterhead.email' => 'nullable|email|max:120',
            'letterhead.website' => 'nullable|string|max:120',
            'letterhead.tax_pin' => 'nullable|string|max:40',
            'invoice_footer' => 'sometimes|nullable|string|max:1000',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['color.regex' => 'The colour must be a hex colour such as #0284c7.'];
    }
}
