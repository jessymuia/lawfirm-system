<?php

namespace App\Http\Controllers;

use App\Http\Requests\Mfa\ConfirmWithPasswordAndCodeRequest;
use App\Http\Requests\Mfa\EnableRequest;
use App\Http\Requests\Mfa\UpdatePolicyRequest;
use App\Models\User;
use App\Security\Mfa;
use App\Security\SecurityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** SEC-12: self-service two-factor (any signed-in user) and the firm's policy / reset (manage-security-settings). */
class MfaController extends Controller
{
    public function __construct(private Mfa $mfa) {}

    public function status(): JsonResponse
    {
        $u = Auth::user();

        return $this->response(true, 'success', [
            'enabled' => Mfa::enabled($u), 'required_by_firm' => Mfa::required(),
            'recovery_codes_left' => Mfa::enabled($u) ? $this->mfa->remaining($u) : 0,
        ], 200);
    }

    public function setup(): JsonResponse
    {
        try {
            return $this->response(true, 'success', $this->mfa->begin(Auth::user()), 200);
        } catch (\RuntimeException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }
    }

    public function enable(EnableRequest $r): JsonResponse
    {
        $d = $r->validated();
        try {
            $codes = $this->mfa->confirm(Auth::user(), $d['code']);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }
        SecurityPolicy::audit('mfa_enabled', 'user', Auth::id(), [], ['mfa' => true]);

        return $this->response(true, 'Two-factor authentication is on. Keep these recovery codes somewhere safe: each works once and they are not shown again.', ['recovery_codes' => $codes], 200);
    }

    /** Needs the password AND a current code, so a hijacked session cannot switch the second factor off. */
    public function disable(ConfirmWithPasswordAndCodeRequest $r): JsonResponse
    {
        $u = Auth::user();
        if (Mfa::required()) {
            return $this->response(false, 'Your firm requires two-factor authentication, so it cannot be turned off.', null, 403);
        }
        if (! $this->proves($r, $u)) {
            return $this->response(false, 'Password or code is not right.', null, 422);
        }
        $this->mfa->disable($u);
        SecurityPolicy::audit('mfa_disabled', 'user', $u->id, ['mfa' => true], []);

        return $this->response(true, 'Two-factor authentication is off.', null, 200);
    }

    public function recoveryCodes(ConfirmWithPasswordAndCodeRequest $r): JsonResponse
    {
        $u = Auth::user();
        if (! Mfa::enabled($u) || ! $this->proves($r, $u)) {
            return $this->response(false, 'Password or code is not right.', null, 422);
        }
        SecurityPolicy::audit('mfa_recovery_codes_regenerated', 'user', $u->id, [], []);

        return $this->response(true, 'New recovery codes. The old ones no longer work.', ['recovery_codes' => $this->mfa->regenerateRecoveryCodes($u)], 200);
    }

    private function proves(ConfirmWithPasswordAndCodeRequest $r, User $u): bool
    {
        $d = $r->validated();

        return Hash::check($d['password'], $u->password) && $this->mfa->check($u, $d['code']);
    }

    // ---- firm policy (manage-security-settings) --------------------------------------------------------------------

    public function policy(): JsonResponse
    {
        return $this->response(true, 'success', SecurityPolicy::get() + ['users_without_mfa' => User::whereNull('platform_staff_id')->count() - DB::table('user_mfa')->whereNotNull('confirmed_at')->count()], 200);
    }

    public function updatePolicy(UpdatePolicyRequest $r): JsonResponse
    {
        $d = $r->validated();
        try {
            return $this->response(true, 'success', SecurityPolicy::setRequireMfa((bool) $d['require_mfa']), 200);
        } catch (\RuntimeException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }
    }

    /** A colleague lost their phone and their recovery codes. Their API tokens are revoked; cookie sessions expire on their own. */
    public function reset(int $user): JsonResponse
    {
        $target = User::whereNull('platform_staff_id')->findOrFail($user);
        $this->mfa->disable($target);
        $target->tokens()->delete();
        SecurityPolicy::audit('mfa_reset_by_admin', 'user', $target->id, ['mfa' => true], []);

        return $this->response(true, 'Their two-factor authentication was reset; they must enrol again at next sign-in.', null, 200);
    }
}
