<?php

namespace App\Http\Requests\Today;

use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: FUN-19 recording a hearing's outcome from court, and the next date if one was given. */
class OutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => 'required|string|max:5000',
            'next_date' => 'nullable|date_format:Y-m-d|after:today',
            'next_time' => 'nullable|date_format:H:i',
            'next_type' => 'nullable|integer|exists:hearing_types,id',
            'next_notes' => 'nullable|string|max:5000',
        ];
    }
}
