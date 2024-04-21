<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesManagementController extends Controller
{
    private function response(bool $success, string $message, ?Collection $data , int $statusCode): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message'=>$message,
            'data' => $data
        ], $statusCode);
    }

    private function unauthorizedError(): \Illuminate\Http\JsonResponse
    {
        return $this->response(false,'Not permitted',null,403);
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $roles = Role::orderByDesc('id')->get();
        $userTimezone = Auth::user()->timezone;

        $roles = $roles->map(function ($role) use($userTimezone){
            $role_created_at = Carbon::parse($role->created_at)->setTimezone($userTimezone);
            $role_updated_at = Carbon::parse($role->updated_at)->setTimezone($userTimezone);
            return [
                'id' => $role->id,
                'name' => $role->name,
                'created_at' => $role_created_at->format('Y-m-d H:i:s'),
                'updated_at' => $role_updated_at->format('Y-m-d H:i:s')
            ];
        });

        return $this->response(true,'Roles retrieved successfully',$roles,200);
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
    public function store(Request $request)
    {
        Log::info($request);
        if(Auth::user()->hasRole('admin') || Auth::user()->hasPermissionTo('create-role')) {
//            $this->authorize('createRoles',Auth::user());
            $data = $request->validate([
                'name' => 'required|string|unique:roles,name',
                'permissions' => 'array',
            ]);

            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);

            if (!empty($data['permissions'])) {
                $permissions = Permission::whereIn('name', $data['permissions'])->get();
                $role->syncPermissions($permissions);
            }
            return $this->index();
        }

        Log::info($request);

        return  $this->unauthorizedError();
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
    public function update(Request $request, string $id)
    {
        Log::info($request);
        $role = Role::where("id",$id)->first();
        $role->name = $request->name;
        $role->syncPermissions([$request->permissions]);
        $role->save();

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $name)
    {
        $role = Role::where("name",$name)->first();
        if($role->delete())
            return $this->index();

        return $this->response(false,"Role could not be deleted",null, 500);
    }

    public function allPermissions() {
        $permissions = Permission::all();
        $permissions = $permissions->map(function ($permission){
            return [
                'name' => $permission->name
            ];
        });
        return $this->response(true,"Permissions retrieved successfully",$permissions, 200);
    }

    public function getRolePermissions(string $id) {
        $role = Role::where("id",$id)->first();
        $permissions = $role->permissions->pluck('name');
        return $this->response(true,"Permissions retrieved successfully",$permissions, 200);
    }
}
