<?php

namespace App\Http\Requests\EthicalScreen;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for EthicalScreenController::lift(). Authorization is declared on the route (authz:*), not here. */
class LiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => 'required|string|min:5|max:1000'];
    }
}
