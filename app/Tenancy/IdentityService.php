<?php

namespace App\Tenancy;

use App\Models\User;
use App\Tenancy\Models\Identity;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TEN-5: control-plane identities and their per-firm memberships.
 *
 * The identity authenticates; the tenant `users` row is the firm-level
 * membership/profile. Deactivating a user in one firm soft-deletes only that
 * tenant's row, so it never affects the person's other firms.
 */
class IdentityService
{
    public function findByEmail(string $email): ?Identity
    {
        return Identity::where('email', Str::lower(trim($email)))->first();
    }

    /**
     * Reuse the person's existing identity or create one.
     * A leftover identity with no memberships (e.g. a provisioning run that
     * died before finishing) gets a fresh password so it is never stranded.
     *
     * @return array{0: Identity, 1: ?string} identity + a generated password to show once, if any
     */
    public function registerOrReuse(string $email, ?string $password = null, ?string $passwordHash = null): array
    {
        $identity = $this->findByEmail($email);

        if ($identity && $identity->memberships()->exists()) {
            return [$identity, null]; // established login: never change it here
        }

        $generated = null;
        if ($password === null && $passwordHash === null) {
            $password = $generated = Str::password(20);
        }
        // PLAT-13: self-service signup stores only the hash of the password the person chose.
        $hash = $passwordHash ?? Hash::make($password);

        if ($identity) {
            $identity->update(['password' => $hash]);
        } else {
            $identity = Identity::create([
                'email' => Str::lower(trim($email)),
                'password' => $hash,
            ]);
        }

        return [$identity, $generated];
    }

    /**
     * TEN-5 / TEN-17: give a person just added to the active firm a way to sign in. In multi-tenant mode login goes
     * through a control-plane identity, so a firm user without one could never sign in. Someone who already works at
     * another firm keeps their existing identity and password; a new person gets $plainPassword. In a single-firm
     * install the user row itself is the login, so there is nothing to do.
     */
    public function enrol(User $user, string $plainPassword): void
    {
        $tenants = app(TenantManager::class);
        $tenant = $tenants->isMulti() ? $tenants->current() : null;
        if (! $tenant || ! $user->email) {
            return;
        }
        [$identity] = $this->registerOrReuse($user->email, $plainPassword);
        $this->link($identity, $tenant, $user);
    }

    /**
     * Link a tenant user to an identity. Call while the tenant is active
     * (the user row lives in the tenant database).
     */
    public function link(Identity $identity, Tenant $tenant, User $user): TenantMembership
    {
        // Keep a copy of the current hash on the tenant row so a tenant dump
        // restored standalone (self-host / eject) can still log in.
        $user->forceFill([
            'global_identity_id' => $identity->id,
            'password' => $identity->password,
        ])->save();

        return TenantMembership::updateOrCreate(
            ['identity_id' => $identity->id, 'tenant_id' => $tenant->id],
            ['tenant_user_id' => $user->id],
        );
    }

    /**
     * Verify credentials for $tenant. Returns that firm's user, or null.
     * Must run with the tenant active (the user is read from its database).
     */
    public function attempt(string $email, string $password, Tenant $tenant): ?User
    {
        $identity = $this->findByEmail($email);

        // Hash against a dummy when the identity is unknown so response time
        // does not reveal which emails exist.
        $hash = $identity->password ?? '$2y$12$abcdefghijklmnopqrstuuFakeFakeFakeFakeFakeFakeFakeFakeFa';
        $passwordOk = Hash::check($password, $hash);

        if (! $identity || ! $passwordOk || ! $identity->isActive()) {
            return null;
        }

        $membership = TenantMembership::where('identity_id', $identity->id)
            ->where('tenant_id', $tenant->id)->first();

        if (! $membership) {
            return null;
        }

        // SoftDeletes hides users deactivated in this firm.
        $user = User::where('id', $membership->tenant_user_id)
            ->where('global_identity_id', $identity->id)
            ->first();

        return $user;
    }

    /** Change the login password everywhere the person signs in. */
    public function changePassword(int $identityId, string $newPassword): void
    {
        Identity::where('id', $identityId)->update(['password' => Hash::make($newPassword)]);
    }

    /** Active firms this identity belongs to (for a firm switcher). */
    public function firmsFor(Identity $identity): Collection
    {
        return Tenant::whereIn('id', $identity->memberships()->pluck('tenant_id'))
            ->where('status', 'active')->orderBy('name')->get(['id', 'slug', 'name']);
    }

    public const RESET_TTL_MINUTES = 60;

    /** Does this email belong to a person with a membership in $tenant? */
    public function isMemberOf(string $email, Tenant $tenant): ?Identity
    {
        $identity = $this->findByEmail($email);

        return $identity && $identity->isActive()
            && TenantMembership::where('identity_id', $identity->id)->where('tenant_id', $tenant->id)->exists()
            ? $identity : null;
    }

    /** Issue a single-use reset token (any earlier one is invalidated). Returns the plain token. */
    public function createResetToken(Identity $identity): string
    {
        $plain = Str::random(64);
        $table = DB::connection(config('tenancy.control_connection'))->table('identity_password_resets');

        $table->where('email', $identity->email)->delete();
        $table->insert([
            'email' => $identity->email,
            'token' => Hash::make($plain),
            'created_at' => Carbon::now(),
        ]);

        return $plain;
    }

    /**
     * Redeem a reset token at $tenant: sets the new password on the identity
     * (so it applies in every firm) and refreshes this firm's hash copy.
     * Requires the tenant to be active (its users table is updated).
     * Single use; expires after RESET_TTL_MINUTES.
     */
    public function redeemResetToken(string $email, string $token, string $newPassword, Tenant $tenant): bool
    {
        $conn = DB::connection(config('tenancy.control_connection'));
        $email = Str::lower(trim($email));

        $record = $conn->table('identity_password_resets')->where('email', $email)->first();
        if (! $record || ! Hash::check($token, $record->token)) {
            return false;
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::RESET_TTL_MINUTES)->isPast()) {
            $conn->table('identity_password_resets')->where('email', $email)->delete();

            return false;
        }

        $identity = $this->isMemberOf($email, $tenant);
        if (! $identity) {
            return false; // a token only works at a firm the person belongs to
        }

        $this->changePassword($identity->id, $newPassword);
        User::where('global_identity_id', $identity->id)->update(['password' => Identity::find($identity->id)->password]);
        $conn->table('identity_password_resets')->where('email', $email)->delete();

        return true;
    }
}
