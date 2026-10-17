<?php

namespace App\Http\Resources\V1;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * ENG-10: the v1 API shape of Role. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from RolesManagementController::formatRow().
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'name' => 'string',
        'created_at' => 'string',
        'updated_at' => 'string',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        $timezone = config('app.timezone');
        $role_created_at = Carbon::parse($role->created_at)->setTimezone($timezone);
        $role_updated_at = Carbon::parse($role->updated_at)->setTimezone($timezone);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'created_at' => $role_created_at->format('Y-m-d H:i:s'),
            'updated_at' => $role_updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
