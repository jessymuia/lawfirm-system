<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-10 portal sign-in, step one (a code is texted to the number if it belongs to a portal client). */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['phone' => 'required|string|max:32'];
    }
}
