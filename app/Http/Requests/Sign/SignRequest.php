<?php

namespace App\Http\Requests\Sign;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-14 a signer signs: their typed name, their drawn signature (PNG), and their confirmation. */
class SignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:191',
            'agree' => 'accepted',
            'signature' => ['required', 'string', 'max:300000', 'regex:#^data:image/png;base64,[A-Za-z0-9+/=]+$#', function ($attr, $value, $fail) {
                $png = base64_decode(substr((string) $value, 22), true);
                $size = $png !== false ? @getimagesizefromstring($png) : false;
                if (! $size || $size[2] !== IMAGETYPE_PNG || $size[0] < 20 || $size[1] < 10) {
                    $fail('Please draw your signature.');
                }
            }],
        ];
    }
}
