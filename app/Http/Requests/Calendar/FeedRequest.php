<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-16 a calendar subscription link: my hearings and tasks, or the court diary. */
class FeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['scope' => 'required|in:mine,diary'];
    }
}
