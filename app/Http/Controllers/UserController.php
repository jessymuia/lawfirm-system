<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private function response(bool $success, string $message,$data , int $statusCode):JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message'=>$message,
            'data' => $data
        ], $statusCode);
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(): \Illuminate\Http\Response|JsonResponse
    {
        if(!Auth::user()->hasPermissionTo(ModulePermissions::LIST_USERS))
            abort(403);
        $users = User::with('roles')->get();
        $usersWithRoles = $users->map(function ($user) {
            $roles = $user->roles;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'department' => $user->department,
                'hire_date' => Carbon::parse($user->hire_date)->format('d/m/Y'),
                'role' => implode(",", $roles->pluck('name')->toArray()),
                'role_id' => $roles->pluck('id')->toArray(),
            ];
        });

        return $this->response(true,'success',$usersWithRoles,200);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request): \Illuminate\Http\Response|JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'phone_number' => $request->phone_number,
            'email' => $request->email,
            'department' => $request->department,
            'hire_date' => Carbon::createFromFormat('d/m/Y', $request->hire_date)->format('Y-m-d'),
            'password' => Hash::make("Password default")
        ]);
//        if($request->has("role")) {
//            $role = Role::find($request->role);
//            if($role) {
//                $user->assignRole($role);
//            }
//        }

        if ($request->has('role')) {
            $roles = $request->role; // Assuming 'role' is an array
            if (is_array($roles)) {
                // Assign multiple roles at once
                $user->assignRole($roles);
            } else {
                // Handle single role as fallback
                $role = Role::find($roles);
                if ($role) {
                    $user->assignRole($role);
                }
            }
        }

        return $this->index();
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id): \Illuminate\Http\Response|JsonResponse
    {
        $user = User::find($id);
        // Check if the new email is different from the current email
        if ($request->email !== $user->email) {
            // Validate that the new email is unique
            $request->validate([
                'email' => 'required|email|unique:users,email,' . $id,
            ]);

            $user->name = $request->name;
            $user->phone_number = $request->phone_number;
            $user->department = $request->department;
            $user->hire_date = Carbon::createFromFormat('d/m/Y', $request->hire_date)->format('Y-m-d');
            $user->email = $request->email;
        } else {
            $user->name = $request->name;
            $user->phone_number = $request->phone_number;
            $user->department = $request->department;
            $user->hire_date = Carbon::createFromFormat('d/m/Y', $request->hire_date)->format('Y-m-d');
            $user->email = $request->email;
        }

        $user->save();

//        if($request->has("role")) {
//            $role = Role::find($request->role);
//            if($role) {
//                $user->syncRoles([$role->name]);
//            }
//        }

        if ($request->has('role')) {
            $roles = $request->role; // Assuming 'role' can be an array or a single value
            if (is_array($roles)) {
                // Sync roles directly with an array
                $user->syncRoles($roles);
            } else {
                // Handle single role as fallback
                $role = Role::find($roles);
                if ($role) {
                    $user->syncRoles([$role->name]);
                }
            }
        }

        return $this->index();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function getRolesDropdown(): JsonResponse
    {
        $roles = Role::orderByDesc('id')->get();
        $roles = $roles->map(function ($row) {
           return [
               "id" => $row->id,
               "name" => $row->name
           ];
        });

        return $this->response(true,'success',$roles,200);
    }
}
