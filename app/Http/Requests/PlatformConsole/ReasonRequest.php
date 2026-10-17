<?php

namespace App\Http\Requests\PlatformConsole;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: platform actions that only need a reason (suspend, resume). Recorded in the platform audit log. */
class ReasonRequest extends FormRequest
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
