<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

/** FUN-17: the period and scope of a partner report. Authorization is on the route. */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group_by' => 'nullable|in:lawyer,office,practice_group',
            'office_id' => 'nullable|integer',
            'practice_group_id' => 'nullable|integer',
            'format' => 'nullable|in:json,csv',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $from = strtotime((string) $this->input('from'));
            $to = strtotime((string) $this->input('to'));
            if ($from && $to && ($to - $from) / 86400 > (int) config('reports.max_days', 731)) {
                $v->errors()->add('to', 'A report covers at most '.config('reports.max_days', 731).' days.');
            }
        });
    }
}
