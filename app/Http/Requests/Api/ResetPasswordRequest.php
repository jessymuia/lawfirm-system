<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

/** ENG-8: input rules for APIController::reset_pass(). Errors keep {success: false, message, errors} (UI-6). */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'token' => ['required', 'max:255'],
            // SEC-14: the same strength as self-service signup (it accepted 8 characters of anything)
            'password' => ['required', 'string', 'confirmed', 'max:200', Password::min(10)->letters()->numbers()],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
