<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\RespondsLikeSendError;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for APIController::loginMfa(). Errors keep the sign-in endpoints' {success, message} shape. */
class LoginMfaRequest extends FormRequest
{
    use RespondsLikeSendError;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['challenge' => 'required|string|max:100', 'code' => 'required|string|max:20'];
    }
}
