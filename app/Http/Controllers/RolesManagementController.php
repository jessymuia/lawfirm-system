<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Http\Requests\RolesManagement\StoreRequest;
use App\Http\Requests\RolesManagement\UpdateRequest;
use App\Http\Resources\V1\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesManagementController extends Controller
{
    private function unauthorizedError(): JsonResponse
    {
        return $this->response(false, 'Not permitted', null, 403);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_ROLES)) {
            abort(403);
        }

        $query = Role::orderByDesc('id');
        $roles = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => RoleResource::make($row)->resolve(),
            25,
            ['name', 'created_at', 'updated_at'],
            ['name']
        );

        return $this->response(true, 'Roles retrieved successfully', $roles, 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRequest $request)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_ROLES)) {
            return $this->unauthorizedError();
        }

        $data = $request->validated();

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);

        if (! empty($data['permissions'])) {
            $permissions = Permission::whereIn('name', $data['permissions'])->get();
            $role->syncPermissions($permissions);
        }

        return $this->response(true, 'success', RoleResource::make($role->fresh())->resolve(), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRequest $request, string $id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_ROLES)) {
            return $this->unauthorizedError();
        }

        $data = $request->validated();

        $role = Role::findOrFail($id);
        $role->name = $data['name'];
        $role->save();
        $role->syncPermissions($data['permissions'] ?? []);

        return $this->response(true, 'success', RoleResource::make($role->fresh())->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $name)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_ROLES)) {
            return $this->unauthorizedError();
        }

        $role = Role::where('name', $name)->firstOrFail();

        if (in_array(strtolower($role->name), ['admin', 'super-admin'], true)) {
            return $this->response(false, 'The admin role cannot be deleted', null, 422);
        }

        $roleId = $role->id;

        if ($role->delete()) {
            return $this->response(true, 'success', ['id' => $roleId], 200);
        }

        return $this->response(false, 'Role could not be deleted', null, 500);
    }

    public function allPermissions()
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_PERMISSIONS)) {
            abort(403);
        }

        $permissions = Permission::all();
        $permissions = $permissions->map(function ($permission) {
            return [
                'name' => $permission->name,
            ];
        });

        return $this->response(true, 'Permissions retrieved successfully', $permissions, 200);
    }

    public function getRolePermissions(string $id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_ROLES)) {
            abort(403);
        }

        $role = Role::where('id', $id)->firstOrFail();
        $permissions = $role->permissions->pluck('name');

        return $this->response(true, 'Permissions retrieved successfully', $permissions, 200);
    }
}
