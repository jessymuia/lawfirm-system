<?php

namespace App\Http\Requests\Sign;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-14 a signer declines, saying why. */
class DeclineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => 'required|string|min:3|max:500'];
    }
}
