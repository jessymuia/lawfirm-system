<?php

namespace App\Http\Requests\Hearing;

use App\Models\Hearing;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for HearingController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use \App\Http\Requests\Concerns\RouteRecord;

    protected string $recordModel = \App\Models\Hearing::class;

    protected string $recordParameter = 'hearing';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start_time' => 'nullable|date_format:H:i',                 // FUN-16: the court diary
            'duration_minutes' => 'nullable|integer|min:5|max:720',
            'courtroom' => 'nullable|string|max:64',
            'judicial_officer' => 'nullable|string|max:120',
            'efiling_reference' => 'nullable|string|max:64',
            'case_id' => 'required|integer|exists:cases,id',
            'court_id' => 'required|integer|exists:courts,id',
            'hearing_date' => 'required|date_format:d/m/Y',
            'hearing_type' => 'required|integer|exists:hearing_types,id',
            'notes' => 'nullable|string',
            'outcome' => 'nullable|string',
        ];
    }
}
