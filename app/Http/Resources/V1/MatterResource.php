<?php

namespace App\Http\Resources\V1;

use App\Models\Cases;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of a matter. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from CasesController::formatRow().
 *
 * @mixin Cases
 */
class MatterResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'case_number' => 'string',
        'description' => '?string',
        'client_id' => '?int',
        'client_public_id' => '?string',
        'client' => 'string',
        'assigned_to' => '?int',
        'attorney' => '?string',
        'start_date' => '?date',
        'end_date' => '?date',
        'case_type' => '?string',
        'case_type_id' => '?int',
        'police_station' => '?string',
        'court_id' => '?int',
        'court' => 'string',
        'opposing_party' => '?string',
        'lifecycle_status' => '?string',
        'client_matter_id' => '?string',
        'office_id' => '?int',
        'office' => '?string',
        'practice_group_id' => '?int',
        'practice_group' => '?string',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Cases $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'case_number' => $row->case_number,
            'description' => $row->description,
            'client_id' => $row->client_id,
            'client_public_id' => $row->client?->publicId(),
            'client' => $row->client->name ?? 'Unknown client',
            'assigned_to' => $row->assigned_to,
            'attorney' => $row->attorney?->name,
            'start_date' => $row->start_date ? Carbon::parse($row->start_date)->format('d/m/Y') : null,
            'end_date' => $row->end_date ? Carbon::parse($row->end_date)->format('d/m/Y') : null,
            'case_type' => $row->caseType->name ?? $row->case_type,   // DATA-12: the firm's list of types
            'case_type_id' => $row->case_type_id,
            'police_station' => $row->police_station,
            'court_id' => $row->court_id,
            'court' => $row->court?->displayName() ?? 'Unknown court',
            'opposing_party' => $row->opposing_party,
            'lifecycle_status' => $row->lifecycle_status,
            // FUN-18
            'client_matter_id' => $row->client_matter_id,   // FUN-13
            'office_id' => $row->office_id,
            'office' => $row->office_id ? $row->office?->name : null,
            'practice_group_id' => $row->practice_group_id,
            'practice_group' => $row->practice_group_id ? $row->practiceGroup?->name : null,
        ];
    }
}
