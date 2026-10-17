<?php

namespace App\Http\Resources\V1;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of Client. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from ClientController::formatRow().
 *
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'public_id' => 'string',
        'name' => 'string',
        'phone_number' => 'string',
        'extra_phone_number' => '?string',
        'address' => '?string',
        'ebilling_client_id' => '?string',
        'advocate' => 'string',
        'advocate_id' => '?int',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Client $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'public_id' => $row->publicId(),   // DATA-9: used in shareable page URLs
            'name' => $row->name,
            'phone_number' => $row->phone_number,
            'extra_phone_number' => $row->extra_phone_number,
            'address' => $row->address,
            'ebilling_client_id' => $row->ebilling_client_id,   // FUN-13
            'advocate' => $row->advocate->name ?? 'Unknown user',
            'advocate_id' => $row->advocate_id,
        ];
    }
}
