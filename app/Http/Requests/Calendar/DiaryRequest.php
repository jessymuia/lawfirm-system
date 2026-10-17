<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-16 the court diary: a date range (at most three months), optionally one court or one lawyer. */
class DiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', function ($attr, $value, $fail) {
                $from = $this->input('from') ?: now()->toDateString();
                if (\Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($value)) > 92) {
                    $fail('The diary covers at most three months at a time.');
                }
            }],
            'court_id' => 'nullable|integer',
            'assigned_to' => 'nullable|integer',
        ];
    }
}
