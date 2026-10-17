<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-10 a portal message (from the client, or from the firm on the matter's portal tab). */
class MessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => 'required|string|max:5000'];
    }
}
