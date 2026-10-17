<?php

namespace App\Http\Requests\RolesManagement;

use App\Http\Requests\Concerns\RouteRecord;
use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Role;

/** ENG-8: input rules for RolesManagementController::update(). Authorization is declared on the route (authz:*), not here. */
class UpdateRequest extends FormRequest
{
    use RouteRecord;

    protected string $recordModel = Role::class;

    protected string $recordParameter = 'role';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->routeId('role');

        return [
            'name' => 'required|string|max:255|unique:roles,name,'.$id,
            'permissions' => 'array',
            'permissions.*' => 'string|exists:permissions,name',
        ];
    }
}
