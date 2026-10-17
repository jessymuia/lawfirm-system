<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Http\Middleware\EnforceMfaPolicy;
use App\Http\Middleware\RejectRevokedSession;
use App\Http\Requests\Api\LoginMfaRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\PasswordRecoveryRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Mail\ForgetPassword;
use App\Models\User;
use App\Security\Mfa;
use App\Sso\SsoService;
use App\Tenancy\IdentityService;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class APIController extends Controller
{
    public function sendError($error, $errorMessages = [], $code = 404): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (! empty($errorMessages)) {
            $response['data'] = $errorMessages;
        }

        return response()->json($response, $code);
    }

    public function sendResponse($result, $message): JsonResponse
    {
        $response = [
            'success' => true,
            'data' => $result,
            'message' => $message,
        ];

        return response()->json($response, 200);
    }

    // custom login
    public function login(LoginRequest $request): JsonResponse
    {

        $throttleKey = Str::lower($request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return $this->sendError("Too many login attempts. Please try again in {$seconds} seconds.", [], 429);
        }

        $credentials = $request->only('email', 'password');
        if ($this->attemptLogin($credentials)) {
            RateLimiter::clear($throttleKey);

            // SEC-12: when the firm enforces SSO, passwords work only for security administrators (the way back in if the IdP is down).
            $sso = app(SsoService::class);
            if ($sso->available() && $sso->provider()->enforce && ! Auth::user()->checkPermissionTo(ModulePermissions::MANAGE_SECURITY_SETTINGS)) {
                Auth::guard('web')->logout();

                return response()->json(['success' => false, 'code' => 'sso_required', 'message' => 'Your firm requires single sign-on. Use "Sign in with SSO".'], 403);
            }

            // SEC-12: a correct password is only the first step for a user with two-factor on. No session yet.
            if (Mfa::enabled(Auth::user())) {
                $challenge = app(Mfa::class)->challenge(Auth::user());
                Auth::guard('web')->logout();

                return $this->sendResponse(['mfa_required' => true, 'challenge' => $challenge], 'mfa_required');
            }

            $request->session()->regenerate();

            return $this->sendResponse($this->sessionPayload($request, Auth::user()), 'success');
        }

        RateLimiter::hit($throttleKey, 60);

        return $this->sendError('Credentials do not match our records', [], 401);
    }

    /** SEC-12: the second step. Any failure is one answer; five wrong tries kill the challenge. */
    public function loginMfa(LoginMfaRequest $request): JsonResponse
    {
        $user = app(Mfa::class)->redeem($request->input('challenge'), $request->input('code'));
        if (! $user) {
            return $this->sendError('That code is not right, or the sign-in expired. Start again.', [], 401);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->sendResponse($this->sessionPayload($request, $user), 'success');
    }

    /** SEC-12: who is signed in, in the login response's shape. The SPA calls it on landing back from the firm's SSO. */
    public function session(Request $request): JsonResponse
    {
        return $this->sendResponse($this->sessionPayload($request, $request->user()), 'success');
    }

    /**
     * [user, may see the dashboard, roles, permissions, must enrol in two-factor first]. Positional for the SPA's
     * existing destructuring; the fifth element tells it to go straight to enrolment rather than meet a 403.
     * checkPermissionTo() returns false when the permission row does not exist, where hasPermissionTo() throws
     * PermissionDoesNotExist (a 500 on any login before permissions are seeded).
     *
     * @return array{0: User, 1: bool, 2: mixed, 3: mixed, 4: bool}
     */
    private function sessionPayload(Request $request, User $user): array
    {
        return [
            $user,
            $user->checkPermissionTo(ModulePermissions::VIEW_DASHBOARD),
            $user->getRoleNames(),
            $user->getAllPermissions()->pluck('name'),
            EnforceMfaPolicy::blocks($request, $user),
        ];
    }

    /**
     * TEN-5: in multi-tenant mode the login is a control-plane identity that
     * must have a membership in the firm this request resolved to. In single
     * mode (self-hosted) the local users table is authoritative, as before.
     */
    private function attemptLogin(array $credentials): bool
    {
        $tenants = app(TenantManager::class);

        if (! $tenants->isMulti()) {
            return Auth::attempt($credentials);
        }

        $tenant = $tenants->current();
        if (! $tenant) {
            return false; // fail closed: no resolved firm, no login
        }

        $user = app(IdentityService::class)
            ->attempt($credentials['email'], $credentials['password'], $tenant);

        if (! $user) {
            return false;
        }

        Auth::guard('web')->login($user);

        return true;
    }

    public function logout(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Revoke any personal access tokens the user may still hold, then
        // end the session-based (cookie) authentication used by the SPA.
        $user?->tokens()->delete();
        // SEC-4: a request still in flight must not write this session back and sign the person in again
        RejectRevokedSession::revoke($request->session()->getId());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->sendResponse(null, 'Successfully logged out');
    }

    // reset password (validation errors keep the {success, message, errors} shape: Api\ResetPasswordRequest, UI-6)
    public function reset_pass(ResetPasswordRequest $request)
    {
        if (app(TenantManager::class)->isMulti()) {
            return $this->multiTenantReset($request);
        }

        try {
            $record = DB::table('password_resets')->where('email', $request->email)->first();

            if (! $record || ! Hash::check($request->token, $record->token)) {
                return $this->sendError('Invalid or expired token.', [], 400);
            }

            $expiryMinutes = 60;
            if (Carbon::parse($record->created_at)->addMinutes($expiryMinutes)->isPast()) {
                DB::table('password_resets')->where('email', $request->email)->delete();

                return $this->sendError('Invalid or expired token.', [], 400);
            }

            // update users password
            User::where('email', $record->email)->update(['password' => Hash::make($request->password)]);

            // delete old data from database
            DB::table('password_resets')->where('email', $record->email)->delete();

            return $this->sendResponse(null, 'success');
        } catch (\Throwable $th) {
            Log::error($th->getMessage());

            return $this->sendError('Something went wrong, please contact support.', [], 500);
        }
    }

    public function passwordRecovery(PasswordRecoveryRequest $request)
    {

        if (app(TenantManager::class)->isMulti()) {
            return $this->multiTenantRecovery($request);
        }

        $user = User::where('email', $request->email)->first();
        if ($user) {
            $token_str = Str::random(64);

            DB::table('password_resets')->where('email', $request->email)->delete();

            DB::table('password_resets')->insert([
                'email' => $request->email,
                'token' => Hash::make($token_str),
                'created_at' => Carbon::now(),
            ]);

            Mail::to($user->email)->send(new ForgetPassword($user->name, $token_str));
        }

        // Always respond the same way whether or not the email exists,
        // so this endpoint can't be used to enumerate registered users.
        return $this->sendResponse(null, 'If that email is registered, a reset link has been sent.');
    }

    /**
     * TEN-5: recovery for a control-plane identity. The link opens on the
     * firm's own domain; the response is identical whether or not the email
     * belongs to this firm, so it can't be used to enumerate members.
     */
    private function multiTenantRecovery(Request $request): JsonResponse
    {
        $throttleKey = 'pwd-recovery|'.Str::lower($request->email).'|'.$request->ip();
        $generic = fn () => $this->sendResponse(null, 'If that email is registered, a reset link has been sent.');

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return $generic();
        }
        RateLimiter::hit($throttleKey, 3600);

        $tenant = app(TenantManager::class)->current();
        $ids = app(IdentityService::class);
        $identity = $tenant ? $ids->isMemberOf($request->email, $tenant) : null;

        if ($identity) {
            $token = $ids->createResetToken($identity);
            $name = User::where('global_identity_id', $identity->id)->value('name') ?? $identity->email;
            $baseUrl = $request->getScheme().'://'.$request->getHttpHost().'/';

            Mail::to($identity->email)->send(new ForgetPassword($name, $token, $baseUrl));
        }

        return $generic();
    }

    private function multiTenantReset(Request $request): JsonResponse
    {
        $tenant = app(TenantManager::class)->current();

        $ok = $tenant && app(IdentityService::class)
            ->redeemResetToken($request->email, $request->token, $request->password, $tenant);

        return $ok ? $this->sendResponse(null, 'success') : $this->sendError('Invalid or expired token.', [], 400);
    }
}
