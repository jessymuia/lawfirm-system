<?php

namespace App\Http\Requests\MatterEmail;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-15 filing a saved email (.eml) to a matter by hand. */
class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => 'required|file|max:'.(int) config('email_ingest.max_message_kb')];
    }
}
