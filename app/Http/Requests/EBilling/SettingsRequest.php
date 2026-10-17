<?php

namespace App\Http\Requests\EBilling;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-13 the firm's LEDES identifiers. */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['law_firm_id' => ['nullable', 'string', 'max:64', 'not_regex:/[|\[\]]/']];
    }
}
