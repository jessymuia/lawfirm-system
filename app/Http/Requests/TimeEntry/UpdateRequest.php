<?php

namespace App\Http\Requests\TimeEntry;

use App\Models\TimeEntry;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for TimeEntryController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use \App\Http\Requests\Concerns\RouteRecord;

    protected string $recordModel = \App\Models\TimeEntry::class;

    protected string $recordParameter = 'time_entry';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'utbms_task_code' => 'nullable|string|in:'.implode(',', array_keys(\App\EBilling\Utbms::TASKS)),   // FUN-13
            'utbms_activity_code' => 'nullable|string|in:'.implode(',', array_keys(\App\EBilling\Utbms::ACTIVITIES)),   // FUN-13
            'case_id' => 'required|integer|exists:cases,id',
            'description' => 'required|string|max:255',
            'date' => 'required|date_format:d/m/Y',
            'hours' => 'required|numeric|min:0.01|max:24',
            'hourly_rate' => 'required|numeric|min:0',
            'billable' => 'boolean',
        ];
    }
}
