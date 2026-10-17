<?php

namespace App\Http\Requests\SupportAccess;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for SupportAccessController::redeem(). Authorization is declared on the route (authz:*), not here. */
class RedeemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => 'required|string|size:40'];
    }
}
