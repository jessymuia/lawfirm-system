<?php

namespace App\Tenancy;

use App\Constants\ModulePermissions;
use App\Models\User;
use App\Security\Mfa;
use App\Security\SecurityPolicy;
use App\Sso\SsoService;
use App\Tenancy\Models\Identity;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * UI-16: one person, several firms (TEN-5), moving between them without signing in again.
 *
 * The firm the person is signed in to issues a single-use token for one of their other firms; the browser takes it to
 * that firm's own host (sessions are per firm, TEN-10), which redeems it and signs the person in there. The token is
 * random, stored only as its SHA-256, valid for SWITCH_SECONDS and bound to the person and both firms. The target
 * firm's own rules still apply: a firm that requires single sign-on, or a person who uses two-factor there, is sent to
 * that firm's sign-in page instead of being let straight in.
 */
class FirmSwitch
{
    public const SWITCH_SECONDS = 60;

    public function __construct(private TenantManager $tenants) {}

    /** @return list<array{slug: string, name: string, current: bool}> the person's active firms (only this one on a single-firm install) */
    public function firms(User $user): array
    {
        $current = $this->tenants->current();
        $identity = $this->identity($user);
        if (! $this->tenants->isMulti() || ! $current || ! $identity) {
            return [['slug' => app(TenantContext::class)->key(), 'name' => (string) config('app.name'), 'current' => true]];
        }

        return app(IdentityService::class)->firmsFor($identity)
            ->map(fn (Tenant $t) => ['slug' => (string) $t->slug, 'name' => (string) $t->name, 'current' => $t->id === $current->id])->values()->all();
    }

    /** @return string|null where to send the browser, or null when the person is not a member of an active firm by that slug */
    public function issue(User $user, string $slug, string $scheme): ?string
    {
        $current = $this->tenants->current();
        $identity = $this->identity($user);
        $target = $current && $identity ? Tenant::where('slug', $slug)->where('status', 'active')->first() : null;
        if (! $target || $target->id === $current->id
            || ! TenantMembership::where('identity_id', $identity->id)->where('tenant_id', $target->id)->exists()) {
            return null;
        }
        $domain = $target->domains()->orderBy('id')->value('domain');
        if (! $domain) {
            return null;
        }
        $token = Str::random(48);
        $this->control()->table('firm_switch_tokens')->insert([
            'token_hash' => hash('sha256', $token), 'identity_id' => $identity->id, 'from_tenant_id' => $current->id, 'to_tenant_id' => $target->id,
            'expires_at' => now()->addSeconds(self::SWITCH_SECONDS), 'created_at' => now(), 'updated_at' => now(),
        ]);
        SecurityPolicy::audit('firm_switch_issued', 'user', (int) $user->id, [], ['to' => $target->slug]);

        return "{$scheme}://{$domain}/switch/{$token}";
    }

    /**
     * On the target firm's host: redeem a token. Returns the user to sign in, or the reason not to
     * ('invalid' | 'sso' | 'mfa'); the token is spent either way.
     *
     * @return array{0: ?User, 1: ?string}
     */
    public function redeem(string $token): array
    {
        $here = $this->tenants->current();
        if (! $here) {
            return [null, 'invalid'];
        }
        $row = $this->control()->transaction(function () use ($token, $here) {
            $row = $this->control()->table('firm_switch_tokens')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $row || $row->used_at !== null || (int) $row->to_tenant_id !== (int) $here->id || now()->greaterThan($row->expires_at)) {
                return null;
            }
            $this->control()->table('firm_switch_tokens')->where('id', $row->id)->update(['used_at' => now(), 'updated_at' => now()]);

            return $row;
        });
        $identity = $row ? Identity::find($row->identity_id) : null;
        $membership = $identity && $identity->isActive()
            ? TenantMembership::where('identity_id', $identity->id)->where('tenant_id', $here->id)->first() : null;
        $user = $membership ? User::where('id', $membership->tenant_user_id)->where('global_identity_id', $identity->id)->first() : null;
        if (! $user) {
            return [null, 'invalid'];
        }
        $sso = app(SsoService::class);
        if ($sso->available() && $sso->provider()->enforce && ! $user->checkPermissionTo(ModulePermissions::MANAGE_SECURITY_SETTINGS)) {
            return [null, 'sso'];
        }
        if (Mfa::enabled($user)) {
            return [null, 'mfa'];
        }

        return [$user, null];
    }

    private function identity(User $user): ?Identity
    {
        return $user->global_identity_id ? Identity::find($user->global_identity_id) : null;
    }

    private function control(): \Illuminate\Database\Connection
    {
        return DB::connection((string) config('tenancy.control_connection'));
    }
}
