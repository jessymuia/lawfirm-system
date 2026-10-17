<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-10 portal sign-in, step two (the texted code). */
class VerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['phone' => 'required|string|max:32', 'code' => 'required|digits:6'];
    }
}
