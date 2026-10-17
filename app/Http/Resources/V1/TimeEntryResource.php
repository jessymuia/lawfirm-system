<?php

namespace App\Http\Resources\V1;

use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of TimeEntry. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from TimeEntryController::formatRow().
 *
 * @mixin TimeEntry
 */
class TimeEntryResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'case_id' => 'int',
        'case' => 'string',
        'user_id' => 'int',
        'user' => 'string',
        'description' => 'string',
        'date' => 'date',
        'hours' => 'decimal',
        'hourly_rate' => 'decimal',
        'amount' => 'decimal',
        'billable' => 'bool',
        'utbms_task_code' => '?string',
        'utbms_activity_code' => '?string',
        'billed' => 'bool',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TimeEntry $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'case_id' => $row->case_id,
            'case' => $row->case->case_number ?? 'Unknown case',
            'user_id' => $row->user_id,
            'user' => $row->user->name ?? 'Unknown user',
            'description' => $row->description,
            'date' => Carbon::parse($row->date)->format('d/m/Y'),
            'hours' => $row->hours,
            'hourly_rate' => $row->hourly_rate,
            'amount' => $row->amount,
            'billable' => $row->billable,
            'utbms_task_code' => $row->utbms_task_code,           // FUN-13
            'utbms_activity_code' => $row->utbms_activity_code,
            'billed' => $row->billed,
        ];
    }
}
