<?php

namespace App\Http\Resources\V1;

use App\Models\Hearing;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of Hearing. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from HearingController::formatRow().
 *
 * @mixin Hearing
 */
class HearingResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'case_id' => 'int',
        'case' => 'string',
        'court_id' => '?int',
        'court' => 'string',
        'hearing_date' => 'date',
        'hearing_type' => '?int',
        'hearing_type_name' => 'string',
        'notes' => '?string',
        'outcome' => '?string',
        'start_time' => '?string',
        'duration_minutes' => '?int',
        'courtroom' => '?string',
        'judicial_officer' => '?string',
        'efiling_reference' => '?string',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Hearing $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'case_id' => $row->case_id,
            'case' => $row->case->case_number ?? 'Unknown case',
            'court_id' => $row->court_id,
            'court' => $row->court?->displayName() ?? 'Unknown court',
            'hearing_date' => Carbon::parse($row->hearing_date)->format('d/m/Y'),
            'hearing_type' => $row->hearing_type,
            'hearing_type_name' => $row->hearingTypeName->name ?? 'Unclassified',
            'notes' => $row->notes,
            'outcome' => $row->outcome,
            // FUN-16: the court diary
            'start_time' => $row->start_time ? substr((string) $row->start_time, 0, 5) : null,
            'duration_minutes' => $row->duration_minutes,
            'courtroom' => $row->courtroom,
            'judicial_officer' => $row->judicial_officer,
            'efiling_reference' => $row->efiling_reference,
        ];
    }
}
