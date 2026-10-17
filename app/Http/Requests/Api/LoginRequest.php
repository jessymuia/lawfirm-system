<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\RespondsLikeSendError;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for APIController::login(). Errors keep the sign-in endpoints' {success, message} shape. */
class LoginRequest extends FormRequest
{
    use RespondsLikeSendError;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['email' => 'required|email|max:250', 'password' => 'required|max:250'];
    }
}
