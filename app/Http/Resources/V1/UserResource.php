<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ENG-10: the v1 API shape of User. One resource per entity; a v2 shape gets its own class under
 * Resources\V2, so a response changes only with the API version (ENG-7). Moved unchanged from UserController::formatRow().
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * ENG-15: the shape this resource answers, key by key, from which `php artisan api:types` generates the web app's
     * TypeScript types. ApiShapesTest checks it against what toArray() really returns.
     */
    public const SHAPE = [
        'id' => 'int',
        'name' => 'string',
        'email' => 'string',
        'phone_number' => '?string',
        'department' => '?string',
        'hire_date' => 'date',
        'role' => 'string',
        'role_id' => 'int[]',
        'timekeeper_class' => '?string',
        'office_id' => '?int',
        'practice_group_id' => '?int',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $roles = $user->roles;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'department' => $user->department,
            'hire_date' => Carbon::parse($user->hire_date)->format('d/m/Y'),
            'role' => implode(',', $roles->pluck('name')->toArray()),
            'role_id' => $roles->pluck('id')->toArray(),
            'timekeeper_class' => $user->timekeeper_class,   // FUN-13
            'office_id' => $user->office_id,                 // FUN-18
            'practice_group_id' => $user->practice_group_id,
        ];
    }
}
