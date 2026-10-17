<?php

namespace App\Http\Requests\PlatformConsole;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformConsoleController::eject(). Authorization is declared on the route (authz:*), not here. */
class EjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => 'required|string|min:5|max:500'];
    }
}
