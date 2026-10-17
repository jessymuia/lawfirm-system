<?php

namespace App\Http\Requests\MatterEmail;

use App\Email\EmailFiling;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-15 who may file email to matters by sending it to their addresses. */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['senders' => 'required|in:'.implode(',', EmailFiling::SENDER_POLICIES)];
    }
}
