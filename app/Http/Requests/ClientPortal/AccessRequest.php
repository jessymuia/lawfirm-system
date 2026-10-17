<?php

namespace App\Http\Requests\ClientPortal;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-10 give or withdraw a client's portal access (giving it texts them an invitation unless told not to). */
class AccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['enabled' => 'required|boolean', 'send_invite' => 'sometimes|boolean'];
    }
}
