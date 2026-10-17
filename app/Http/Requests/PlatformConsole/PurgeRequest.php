<?php

namespace App\Http\Requests\PlatformConsole;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for PlatformConsoleController::purge(). Authorization is declared on the route (authz:*), not here. */
class PurgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['confirm' => 'required|string', 'reason' => 'required|string|min:5|max:500', 'without_export' => 'nullable|string|min:5|max:500'];
    }
}
