<?php

namespace App\Http\Resources\V1;

use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of Task. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from TaskController::formatRow().
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'description' => '?string',
        'title' => 'string',
        'assigned_to' => '?int',
        'advocate' => 'string',
        'due_date' => 'date',
        'priority' => '?string',
        'status' => '?string',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Task $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'description' => $row->description,
            'title' => $row->title,
            'assigned_to' => $row->assigned_to,
            'advocate' => $row->assignee->name ?? 'Unknown user',
            'due_date' => Carbon::parse($row->due_date)->format('d/m/Y'),
            'priority' => $row->priority,
            'status' => $row->task_status,
        ];
    }
}
