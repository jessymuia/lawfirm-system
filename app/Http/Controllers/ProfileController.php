<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateRequest;
use App\Tenancy\IdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    /**
     * UI-1 follow-up: users/account_setting.vue — the page linked from
     * the header's "My Account" dropdown — was, in its entirety, a
     * leftover copy of case-detail-viewing markup (case fields, a
     * documents table, a hearings table) with nothing related to
     * account settings anywhere in it. Every user who opened their own
     * account page saw a broken, nonsensical screen. This controller is
     * the real backend the rebuilt page (see the .vue file's own
     * comment) needed and never had.
     */
    public function show(): JsonResponse
    {
        $user = Auth::user();

        return $this->response(true, 'success', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'department' => $user->department,
        ], 200);
    }

    public function update(UpdateRequest $request): JsonResponse
    {
        $user = Auth::user();

        $data = $request->validated();

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'] ?? $user->phone_number,
            'updated_by' => $user->id,
        ]);

        return $this->response(true, 'Profile updated successfully.', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'department' => $user->department,
        ], 200);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = Auth::user();

        $data = $request->validated();

        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->response(false, 'Current password is incorrect.', null, 422);
        }

        $user->update([
            'password' => Hash::make($data['new_password']),
            'updated_by' => $user->id,
        ]);

        // TEN-5: the login password lives on the control-plane identity.
        if ($user->global_identity_id) {
            app(IdentityService::class)->changePassword($user->global_identity_id, $data['new_password']);
        }

        return $this->response(true, 'Password updated successfully.', null, 200);
    }
}
