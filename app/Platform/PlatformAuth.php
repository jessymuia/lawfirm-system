<?php

namespace App\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** PLAT-7: login, bearer tokens (stored hashed), lockout. */
class PlatformAuth
{
    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    /** @return array{token: string, staff: PlatformStaff, expires_at: \Illuminate\Support\Carbon}|array{mfa_required: true, challenge: string, staff: PlatformStaff}|null null = bad credentials (one answer for unknown/locked/disabled/wrong) */
    public function login(string $email, string $password, ?string $ip = null): ?array
    {
        $staff = PlatformStaff::where('email', mb_strtolower($email))->first();

        if (! $staff || ! $staff->active || ($staff->locked_until && $staff->locked_until->isFuture())) {
            Hash::check($password, '$2y$12$usesomesillystringforsaltuQ0eKx3vjO7m4G1vYh3o6l1Y2W9xq6u');   // equalise timing
            PlatformAudit::record($staff, 'login', null, [], 'denied', $staff ? ($staff->active ? 'locked' : 'disabled') : 'unknown email', $ip, $email);

            return null;
        }

        if (! Hash::check($password, $staff->password)) {
            $failures = $staff->failed_logins + 1;
            $staff->update(['failed_logins' => $failures, 'locked_until' => $failures >= self::MAX_FAILURES ? now()->addMinutes(self::LOCK_MINUTES) : null]);
            PlatformAudit::record($staff, 'login', null, [], 'denied', 'bad password', $ip);

            return null;
        }

        // SEC-12: with a second factor on, the password alone gets nothing but a challenge.
        if ($this->mfa()->enabled($staff->id)) {
            PlatformAudit::record($staff, 'login', null, [], 'ok', 'password accepted, second factor required', $ip);

            return ['mfa_required' => true, 'challenge' => $this->mfa()->challenge($staff->id), 'staff' => $staff];
        }

        // No second factor yet: allowed (optional) or limited to enrolling it (required).
        $enrollOnly = config('tenancy.platform_mfa') === 'required';
        $staff->update(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()]);

        return $this->issue($staff, $ip, $enrollOnly);
    }

    /** The second step. @return array{token: string, staff: PlatformStaff, expires_at: \Illuminate\Support\Carbon}|null */
    public function completeMfa(string $challenge, string $code, ?string $ip = null): ?array
    {
        $owner = $this->mfa()->challengeSubject($challenge);
        $id = $this->mfa()->redeem($challenge, $code);
        $staff = PlatformStaff::find($id ?? $owner);
        if (! $id || ! $staff || ! $staff->active || ($staff->locked_until && $staff->locked_until->isFuture())) {
            PlatformAudit::record($staff, 'login.mfa', null, [], 'denied', 'bad code, expired challenge or locked', $ip);
            if ($staff) {
                $this->countFailure($staff);   // a wrong code is charged to the person the challenge was issued to
            }

            return null;
        }
        $staff->update(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()]);

        return $this->issue($staff, $ip, false);
    }

    /** A wrong second-factor attempt counts toward the same lockout as a wrong password. */
    public function countFailure(PlatformStaff $staff): void
    {
        $failures = $staff->failed_logins + 1;
        $staff->update(['failed_logins' => $failures, 'locked_until' => $failures >= self::MAX_FAILURES ? now()->addMinutes(self::LOCK_MINUTES) : null]);
    }

    private function issue(PlatformStaff $staff, ?string $ip, bool $enrollOnly): array
    {
        $token = Str::random(48);
        $expires = now()->addHours((int) config('tenancy.platform_token_hours', 8));
        DB::connection(config('tenancy.control_connection'))->table('platform_tokens')->insert([
            'staff_id' => $staff->id, 'token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'created_at' => now(), 'enroll_only' => $enrollOnly,
        ]);
        PlatformAudit::record($staff, 'login', null, [], 'ok', $enrollOnly ? 'limited to enrolling two-factor' : null, $ip);

        return ['token' => $token, 'staff' => $staff, 'expires_at' => $expires, 'enroll_only' => $enrollOnly];
    }

    public function mfa(): \App\Security\MfaCore
    {
        return new \App\Security\MfaCore(config('tenancy.control_connection'), 'platform_staff_mfa', 'platform_mfa_challenges', 'staff_id');
    }

    /** Set after authenticate(): did the presented token come from a login that must still enrol two-factor? */
    private bool $enrollOnly = false;

    public function enrollOnly(): bool
    {
        return $this->enrollOnly;
    }

    /** Enrolment finished: the current token becomes a normal one. */
    public function liftEnrollOnly(?string $token): void
    {
        $token && DB::connection(config('tenancy.control_connection'))->table('platform_tokens')->where('token_hash', hash('sha256', $token))->update(['enroll_only' => false]);
    }

    public function authenticate(?string $token): ?PlatformStaff
    {
        $this->enrollOnly = false;
        if (! $token) {
            return null;
        }
        $db = DB::connection(config('tenancy.control_connection'));
        $row = $db->table('platform_tokens')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        if (! $row) {
            return null;
        }
        $staff = PlatformStaff::find($row->staff_id);
        if (! $staff || ! $staff->active) {
            return null;
        }
        $db->table('platform_tokens')->where('id', $row->id)->update(['last_used_at' => now()]);
        $this->enrollOnly = (bool) $row->enroll_only;

        return $staff;
    }

    public function logout(string $token): void
    {
        DB::connection(config('tenancy.control_connection'))->table('platform_tokens')->where('token_hash', hash('sha256', $token))->delete();
    }

    /** Disabling staff also kills their live tokens. */
    public function revokeAll(PlatformStaff $staff): void
    {
        DB::connection(config('tenancy.control_connection'))->table('platform_tokens')->where('staff_id', $staff->id)->delete();
    }
}
