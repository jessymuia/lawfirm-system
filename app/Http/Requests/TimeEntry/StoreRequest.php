<?php

namespace App\Http\Requests\TimeEntry;

use App\EBilling\Utbms;
use Illuminate\Foundation\Http\FormRequest;

/** ENG-8: input rules for TimeEntryController::store(). Authorization is declared on the route (authz:*), not here. */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'utbms_task_code' => 'nullable|string|in:'.implode(',', array_keys(Utbms::TASKS)),   // FUN-13
            'utbms_activity_code' => 'nullable|string|in:'.implode(',', array_keys(Utbms::ACTIVITIES)),   // FUN-13
            'case_id' => 'required|integer|exists:cases,id',
            'description' => 'required|string|max:255',
            'date' => 'required|date_format:d/m/Y',
            'hours' => 'required|numeric|min:0.01|max:24',
            'hourly_rate' => 'required|numeric|min:0',
            'billable' => 'boolean',
        ];
    }
}
