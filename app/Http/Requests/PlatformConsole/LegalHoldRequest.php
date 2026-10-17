<?php

namespace App\Http\Requests\PlatformConsole;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformConsoleController::legalHold(). Authorization is declared on the route (authz:*), not here. */
class LegalHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['hold' => 'required|boolean', 'reason' => 'required|string|min:5|max:500'];
    }
}
