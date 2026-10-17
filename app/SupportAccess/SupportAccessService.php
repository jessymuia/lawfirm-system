<?php

namespace App\SupportAccess;

use App\Constants\ModulePermissions;
use App\Mail\SupportAccessNotice;
use App\Models\SupportAccessSession;
use App\Models\User;
use App\Platform\PlatformStaff;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PLAT-8: the lifecycle of platform-support access. Every method runs INSIDE the tenant's context (the platform controller wraps
 * calls in TenantManager::runIn); only the policy, tenant row and one-time codes live in the control plane.
 *
 *   request -> [firm approves] -> start (ephemeral principal + one-time code) -> ... -> end | expire | revoke
 */
class SupportAccessService
{
    public const REQUEST_VALID_HOURS = 24;

    public const CODE_VALID_MINUTES = 5;

    public const ROLE_READ = 'support-read';

    public const ROLE_FULL = 'support-full';

    public function request(PlatformStaff $staff, Tenant $tenant, string $reason, int $minutes, string $mode): SupportAccessSession
    {
        $policy = SupportPolicy::for($tenant);
        if (! $policy['enabled']) {
            throw new RuntimeException('This firm has turned off platform support access.');
        }
        if ($mode === 'full' && ! $policy['allow_write']) {
            throw new RuntimeException('This firm only allows read-only support access.');
        }
        if ($minutes > $policy['max_minutes']) {
            throw new RuntimeException("This firm limits support sessions to {$policy['max_minutes']} minutes.");
        }
        $this->expireDue();
        if (SupportAccessSession::where('platform_staff_id', $staff->id)->whereIn('status', ['pending', 'approved', 'active'])->exists()) {
            throw new RuntimeException('You already have an open support session with this firm; end it first.');
        }

        $auto = ! $policy['require_approval'];
        $session = SupportAccessSession::create([
            'platform_staff_id' => $staff->id, 'staff_name' => $staff->name, 'staff_email' => $staff->email,
            'reason' => $reason, 'mode' => $mode, 'requested_minutes' => $minutes,
            'status' => $auto ? 'approved' : 'pending', 'requested_at' => now(), 'approved_at' => $auto ? now() : null,
        ]);
        $this->notify($session, $auto ? 'Platform support has been granted access under your standing policy.' : 'Platform support is asking for access and needs your approval.');

        return $session;
    }

    public function approve(SupportAccessSession $s, User $by): SupportAccessSession
    {
        $this->requireStatus($s, ['pending'], 'approve');
        $s->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()]);

        return $s;
    }

    public function deny(SupportAccessSession $s, User $by): SupportAccessSession
    {
        $this->requireStatus($s, ['pending'], 'deny');
        $s->update(['status' => 'denied', 'approved_by' => $by->id, 'ended_at' => now(), 'ended_by' => 'firm']);

        return $s;
    }

    /** @return array{session: SupportAccessSession, code: string} the code is shown once and works once, within minutes */
    public function start(SupportAccessSession $s, Tenant $tenant, PlatformStaff $staff): array
    {
        $this->requireStatus($s, ['approved'], 'start');
        if ($s->platform_staff_id !== $staff->id) {
            throw new RuntimeException('Only the person who requested this session can start it.');
        }
        if ($s->requested_at->addHours(self::REQUEST_VALID_HOURS)->isPast()) {
            $s->update(['status' => 'expired', 'ended_at' => now(), 'ended_by' => 'expiry']);
            throw new RuntimeException('This request is older than '.self::REQUEST_VALID_HOURS.' hours; ask again.');
        }
        // The firm may have tightened its policy since the request.
        $policy = SupportPolicy::for($tenant);
        if (! $policy['enabled'] || ($s->mode === 'full' && ! $policy['allow_write'])) {
            throw new RuntimeException('The firm\'s support policy no longer allows this session.');
        }

        $minutes = min($s->requested_minutes, $policy['max_minutes']);
        $principal = $this->createPrincipal($s);
        $s->update(['status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addMinutes($minutes), 'user_id' => $principal->id]);

        $code = Str::random(40);
        DB::connection(config('tenancy.control_connection'))->table('support_exchange_codes')->insert([
            'tenant_id' => $tenant->id, 'session_id' => $s->id, 'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(self::CODE_VALID_MINUTES), 'created_at' => now(),
        ]);
        $this->notify($s, 'Platform support has started a session in your firm.');

        return ['session' => $s->fresh(), 'code' => $code];
    }

    /** Called on the tenant's own host: one-time code -> the principal to log in. Null for anything not exactly valid. */
    public function redeem(string $code, Tenant $tenant): ?User
    {
        $db = DB::connection(config('tenancy.control_connection'));
        $row = $db->table('support_exchange_codes')->where('code_hash', hash('sha256', $code))
            ->where('tenant_id', $tenant->id)->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (! $row) {
            return null;
        }
        // Single use, even under a race: only the request that flips used_at gets the login.
        if ($db->table('support_exchange_codes')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
            return null;
        }
        $s = SupportAccessSession::find($row->session_id);
        if (! $s || ! $s->isActive() || ! $s->user_id) {
            return null;
        }

        return User::find($s->user_id);
    }

    /** End a session now: the principal stops existing, so every cookie/token it holds dies with it. */
    public function end(SupportAccessSession $s, string $by, string $finalStatus = 'ended'): SupportAccessSession
    {
        if (! in_array($s->status, ['pending', 'approved', 'active'], true)) {
            throw new RuntimeException("Session is already {$s->status}.");
        }
        if ($s->user_id && ($user = User::find($s->user_id))) {
            $user->syncRoles([]);
            $user->tokens()->delete();
            $user->delete();
        }
        $s->update(['status' => $finalStatus, 'ended_at' => now(), 'ended_by' => $by]);

        return $s;
    }

    /** Sweeper (support:expire) and a cheap guard before new requests: nothing outlives its window. */
    public function expireDue(): int
    {
        $n = 0;
        foreach (SupportAccessSession::where('status', 'active')->where('expires_at', '<=', now())->get() as $s) {
            $this->end($s, 'expiry', 'expired');
            $n++;
        }
        foreach (SupportAccessSession::whereIn('status', ['pending', 'approved'])->where('requested_at', '<=', now()->subHours(self::REQUEST_VALID_HOURS))->get() as $s) {
            $s->update(['status' => 'expired', 'ended_at' => now(), 'ended_by' => 'expiry']);
            $n++;
        }

        return $n;
    }

    // ---- internals ----------------------------------------------------------------------------------------------

    private function createPrincipal(SupportAccessSession $s): User
    {
        $this->syncRoles();
        $user = User::create([
            'name' => 'Support: '.$s->staff_name.' (platform)',
            'email' => "support-{$s->id}-".Str::lower(Str::random(8)).'@support.invalid',
            'password' => Hash::make(Str::random(48)),   // nobody knows it; this account can only be entered through a one-time code
            'phone_number' => '', 'department' => 'Platform support', 'hire_date' => now(),
        ]);
        $user->forceFill(['platform_staff_id' => $s->platform_staff_id])->save();
        $user->assignRole($s->mode === 'full' ? self::ROLE_FULL : self::ROLE_READ);

        return $user;
    }

    /** Re-derived on every start so permissions added by a release reach (or stay out of) support roles. */
    private function syncRoles(): void
    {
        $all = Permission::all();
        $read = $all->filter(fn ($p) => Str::startsWith($p->name, ['list-', 'view-']));
        $neverSupport = ['export-firm-data', 'access-all-matters'];
        $full = $all->filter(fn ($p) => ! Str::startsWith($p->name, 'manage-')
            && ! in_array($p->name, $neverSupport, true)
            && ! preg_match('/-(roles|permissions)$/', $p->name));

        Role::firstOrCreate(['name' => self::ROLE_READ, 'guard_name' => 'web'])->syncPermissions($read);
        Role::firstOrCreate(['name' => self::ROLE_FULL, 'guard_name' => 'web'])->syncPermissions($full);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function requireStatus(SupportAccessSession $s, array $allowed, string $action): void
    {
        if (! in_array($s->status, $allowed, true)) {
            throw new RuntimeException("Cannot {$action} a session that is {$s->status}.");
        }
    }

    private function notify(SupportAccessSession $s, string $headline): void
    {
        try {
            $admins = User::permission(ModulePermissions::MANAGE_SUPPORT_ACCESS)->whereNull('platform_staff_id')->get();
            foreach ($admins as $admin) {
                Mail::to($admin->email)->queue(new SupportAccessNotice($s, $headline));
            }
        } catch (\Throwable $e) {
            report($e);   // a mail outage must never block, or hide, the access record itself
        }
    }
}
