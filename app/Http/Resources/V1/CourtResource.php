<?php

namespace App\Http\Resources\V1;

use App\Models\Court;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of Court. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from CourtController::formatRow().
 *
 * @mixin Court
 */
class CourtResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'name' => 'string',
        'official_name' => 'string',
        'alias' => '?string',
        'is_system' => 'bool',
        'retired' => 'bool',
        'type' => '?int',
        'court_type' => 'string',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Court $row */
        $row = $this->resource;

        // TEN-11: `name` is what the firm sees (its alias, if set); official_name/is_system say whose row it is.
        return ['id' => $row->id] + $row->catalogFields() + [
            'type' => $row->type,
            'court_type' => $row->courtType?->displayName() ?? 'Unknown type',
        ];
    }
}
