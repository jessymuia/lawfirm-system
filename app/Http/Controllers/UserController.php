<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_USERS)) {
            abort(403);
        }

        $query = User::with('roles')->orderBy('id', 'desc');
        // FUN-18: by office / practice group
        foreach (['office_id', 'practice_group_id', 'timekeeper_class'] as $f) {   // FUN-13: timekeeper_class likewise
            if (request()->filled($f)) {
                $query->where($f, (int) request()->input($f));
            }
        }
        $usersWithRoles = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => \App\Http\Resources\V1\UserResource::make($row)->resolve(),
            25,
            ['name', 'email', 'phone_number', 'department', 'hire_date'],
            ['name', 'email', 'phone_number', 'department']
        );

        return $this->response(true, 'success', $usersWithRoles, 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(\App\Http\Requests\User\StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_USERS)) {
            abort(403);
        }

        // Str::password() only exists from Laravel 10 — on this Laravel 9
        // install it throws BadMethodCallException (a 500 on every user
        // creation). Str::random() is the 9.x-compatible equivalent.
        $temporaryPassword = Str::random(16);

        $user = User::create([
            'name' => $request->name,
            'phone_number' => $request->phone_number,
            'email' => $request->email,
            'department' => $request->department,
            'hire_date' => Carbon::createFromFormat('d/m/Y', $request->hire_date)->format('Y-m-d'),
            'password' => Hash::make($temporaryPassword),
            'office_id' => $request->input('office_id'),                 // FUN-18
            'practice_group_id' => $request->input('practice_group_id'),
            'timekeeper_class' => $request->input('timekeeper_class'),   // FUN-13
        ]);

        // TEN-5: in a multi-tenant deployment the sign-in is a control-plane identity (no-op on a single-firm install)
        app(\App\Tenancy\IdentityService::class)->enrol($user, $temporaryPassword);

        // TODO: email $temporaryPassword to the user via a proper invite/reset
        // flow instead of a fixed known string, once ENG-3's mail queue is in place.

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

        return $this->response(true, 'success', \App\Http\Resources\V1\UserResource::make($user->load('roles'))->resolve(), 201);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(\App\Http\Requests\User\UpdateRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_USERS)) {
            abort(403);
        }

        $user = User::findOrFail($id);

        $user->name = $request->name;
        $user->phone_number = $request->phone_number;
        $user->department = $request->department;
        $user->hire_date = Carbon::createFromFormat('d/m/Y', $request->hire_date)->format('Y-m-d');
        $user->email = $request->email;
        // FUN-18: only when sent, so older clients that do not know these fields leave them alone
        foreach (['office_id', 'practice_group_id'] as $f) {
            if ($request->exists($f)) {
                $user->{$f} = $request->input($f);
            }
        }
        $user->save();

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

        return $this->response(true, 'success', \App\Http\Resources\V1\UserResource::make($user->fresh('roles'))->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * User accounts are soft-deleted, never hard-deleted — too many other
     * tables reference users by id (created_by, assigned_to, recorded_by,
     * etc.) for a hard delete to be safe. A deactivated user simply stops
     * being able to log in (soft-deleted users fail Auth::attempt's
     * default query scope) while every historical record they created
     * remains intact and attributable.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_USERS)) {
            abort(403);
        }

        $user = User::findOrFail($id);

        if ($user->id === auth()->user()->id) {
            return $this->response(false, 'You cannot deactivate your own account', null, 422);
        }

        // Same reason as booted()/update() above: `users` has no
        // deleted_by column, unlike every other soft-deletable table.
        $user->delete();

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }

    public function getRolesDropdown(): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_ROLES)) {
            abort(403);
        }

        $roles = Role::orderByDesc('id')->get();
        $roles = $roles->map(function ($row) {
            return [
                'id' => $row->id,
                'name' => $row->name,
            ];
        });

        return $this->response(true, 'success', $roles, 200);
    }
}
