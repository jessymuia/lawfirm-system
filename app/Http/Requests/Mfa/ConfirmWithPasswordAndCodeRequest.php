<?php

namespace App\Http\Requests\Mfa;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: turning MFA off or replacing recovery codes needs the password AND a current code (MfaController). */
class ConfirmWithPasswordAndCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => 'required|string|max:250', 'code' => 'required|string|max:20'];
    }
}
