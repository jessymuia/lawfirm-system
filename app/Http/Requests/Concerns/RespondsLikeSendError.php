<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * ENG-8: the sign-in endpoints answered validation errors in APIController::sendError's shape
 * ({success: false, message: <first error>}) before they had Form Requests; clients rely on it, so it stays.
 */
trait RespondsLikeSendError
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['success' => false, 'message' => $validator->errors()->first()], 422));
    }
}
