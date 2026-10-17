<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Entitlements\EntitlementException;
use App\Entitlements\Entitlements;
use App\Http\Middleware\ScimTokenAuth;
use App\Http\Requests\Scim\CreateTokenRequest;
use App\Http\Requests\Scim\ScimPatchRequest;
use App\Http\Requests\Scim\ScimUserRequest;
use App\Models\User;
use App\Security\SecurityPolicy;
use App\Tenancy\IdentityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * SEC-12: SCIM 2.0 user provisioning (RFC 7643/7644) for a firm's identity provider.
 *
 * Deliberately narrow: users only, no groups, no bulk. The IdP can create people (with the role chosen on the token), change their
 * name, and deactivate or reactivate them; it cannot change an email address (that would break the sign-in link), touch platform staff, or
 * remove the firm's last security administrator. Deactivation is the same soft delete the app uses, so history stays attributable.
 */
class ScimController extends Controller
{
    private const USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    /** @return Builder<User> */
    private function base(): Builder
    {
        return User::withTrashed()->whereNull('platform_staff_id');
    }

    private function view(User $u): array
    {
        $base = request()->getSchemeAndHttpHost().'/api/v1/scim/v2/Users/'.$u->id;
        [$given, $family] = array_pad(explode(' ', (string) $u->name, 2), 2, '');

        return [
            'schemas' => [self::USER], 'id' => (string) $u->id, 'userName' => $u->email, 'displayName' => $u->name,
            'name' => ['formatted' => $u->name, 'givenName' => $given, 'familyName' => $family],
            'emails' => [['value' => $u->email, 'type' => 'work', 'primary' => true]],
            'active' => $u->deleted_at === null,
            'meta' => ['resourceType' => 'User', 'created' => optional($u->created_at)->toIso8601String(), 'lastModified' => optional($u->updated_at)->toIso8601String(), 'location' => $base],
        ];
    }

    private function boolean(mixed $v): bool
    {
        return is_bool($v) ? $v : in_array(strtolower((string) $v), ['true', '1', 'yes'], true);
    }

    private function nameFrom(array $d, string $fallback): string
    {
        $n = $d['name'] ?? [];
        $name = trim((string) ($n['formatted'] ?? ''));
        if ($name === '') {
            $name = trim(($n['givenName'] ?? '').' '.($n['familyName'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string) ($d['displayName'] ?? ''));
        }

        return $name !== '' ? $name : Str::before($fallback, '@');
    }

    private function audit(Request $r, string $event, User $u, array $old = [], array $new = []): void
    {
        SecurityPolicy::audit($event, 'user', (int) $u->id, $old, $new + ['via' => 'scim', 'token' => $r->attributes->get('scim_token')->name ?? null]);
    }

    // ---- discovery ------------------------------------------------------------------------------------------------

    public function serviceProviderConfig(): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true], 'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 100], 'changePassword' => ['supported' => false], 'sort' => ['supported' => false], 'etag' => ['supported' => false],
            'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer token', 'description' => 'A provisioning token issued under Settings, Security.', 'primary' => true]],
        ]);
    }

    public function resourceTypes(): JsonResponse
    {
        return response()->json(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'], 'totalResults' => 1, 'Resources' => [[
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'], 'id' => 'User', 'name' => 'User', 'endpoint' => '/Users', 'schema' => self::USER,
        ]]]);
    }

    public function schemas(): JsonResponse
    {
        return response()->json(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'], 'totalResults' => 1, 'Resources' => [['id' => self::USER, 'name' => 'User']]]);
    }

    // ---- users ----------------------------------------------------------------------------------------------------

    public function index(Request $r): JsonResponse
    {
        $q = $this->base();
        if ($filter = trim((string) $r->query('filter', ''))) {
            // the one filter provisioning clients use to find a person: userName eq "x" (or emails.value eq "x")
            if (! preg_match('/^(userName|emails\.value)\s+eq\s+"([^"]{1,255})"$/i', $filter, $m)) {
                return ScimTokenAuth::error(400, 'Only: userName eq "value".', 'invalidFilter');
            }
            $q->whereRaw('lower(email) = ?', [Str::lower($m[2])]);
        }
        $total = (clone $q)->count();
        $start = max(1, (int) $r->query('startIndex', 1));
        $count = min(100, max(0, (int) $r->query('count', 100)));
        $rows = $q->orderBy('id')->skip($start - 1)->take($count)->get();

        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'], 'totalResults' => $total, 'startIndex' => $start, 'itemsPerPage' => $rows->count(),
            'Resources' => $rows->map(fn ($u) => $this->view($u))->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $u = $this->base()->whereKey($id)->first();

        return $u ? response()->json($this->view($u)) : ScimTokenAuth::error(404, 'User not found.');
    }

    public function store(ScimUserRequest $r): JsonResponse
    {
        $d = $r->validated();
        $email = Str::lower(trim($d['userName']));
        // the address must be free across every account, including ones SCIM cannot see (platform support principals)
        if (User::withTrashed()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return ScimTokenAuth::error(409, 'A user with that userName already exists.', 'uniqueness');
        }
        try {
            app(Entitlements::class)->requireWithin('users');
        } catch (EntitlementException) {
            return ScimTokenAuth::error(403, 'The plan\'s user limit has been reached.');
        }

        $active = $this->boolean($d['active'] ?? true);
        $user = DB::transaction(function () use ($d, $email, $r, $active) {
            $temporary = Str::random(24);   // never shown: the person signs in through the identity provider
            $user = User::create([
                'name' => $this->nameFrom($d, $email), 'email' => $email, 'password' => Hash::make($temporary),
                'phone_number' => '', 'department' => '', 'hire_date' => now()->toDateString(),
            ]);
            app(IdentityService::class)->enrol($user, $temporary);
            $roleId = $r->attributes->get('scim_token')->default_role_id ?? null;
            if ($roleId && ($role = Role::find($roleId))) {
                $user->assignRole($role);
            }
            if (! $active) {
                $user->delete();
            }

            return $user;
        });
        $this->audit($r, 'scim_user_created', $user, [], ['email' => $email, 'active' => $active]);

        return response()->json($this->view(User::withTrashed()->find($user->id)), 201, ['Location' => request()->getSchemeAndHttpHost().'/api/v1/scim/v2/Users/'.$user->id]);
    }

    public function replace(ScimUserRequest $r, int $id): JsonResponse
    {
        $u = $this->base()->whereKey($id)->first();
        if (! $u) {
            return ScimTokenAuth::error(404, 'User not found.');
        }
        $d = $r->validated();
        if (Str::lower(trim($d['userName'])) !== Str::lower($u->email)) {
            return ScimTokenAuth::error(400, 'userName (the email address) cannot be changed here.', 'mutability');
        }

        return $this->apply($r, $u, $this->nameFrom($d, $u->email), array_key_exists('active', $d) ? $this->boolean($d['active']) : null);
    }

    public function patch(ScimPatchRequest $r, int $id): JsonResponse
    {
        $u = $this->base()->whereKey($id)->first();
        if (! $u) {
            return ScimTokenAuth::error(404, 'User not found.');
        }
        $given = null;
        $family = null;
        [$curGiven, $curFamily] = array_pad(explode(' ', (string) $u->name, 2), 2, '');
        $name = null;
        $active = null;

        foreach ($r->validated()['Operations'] as $op) {
            $verb = strtolower($op['op']);
            $path = $op['path'] ?? null;
            $value = $op['value'] ?? null;
            // without a path, the value is an object of attributes (Entra and Okta both do this)
            $pairs = $path === null && is_array($value) ? $value : ($path !== null ? [$path => $value] : []);
            foreach ($pairs as $k => $v) {
                $k = strtolower((string) $k);
                if ($verb === 'remove') {
                    continue;   // nothing here is removable; a no-op is the safe answer
                }
                if ($k === 'active') {
                    $active = $this->boolean($v);
                } elseif ($k === 'displayname' || $k === 'name.formatted') {
                    $name = trim((string) $v);
                } elseif ($k === 'name.givenname') {
                    $given = trim((string) $v);
                } elseif ($k === 'name.familyname') {
                    $family = trim((string) $v);
                } elseif ($k === 'name' && is_array($v)) {
                    $name = trim((string) ($v['formatted'] ?? trim(($v['givenName'] ?? $curGiven).' '.($v['familyName'] ?? $curFamily))));
                } elseif ($k === 'username' && Str::lower(trim((string) $v)) !== Str::lower($u->email)) {
                    return ScimTokenAuth::error(400, 'userName (the email address) cannot be changed here.', 'mutability');
                }
                // anything else (emails, externalId, phone numbers, enterprise attributes) is accepted and ignored
            }
        }
        if ($name === null && ($given !== null || $family !== null)) {
            $name = trim(($given ?? $curGiven).' '.($family ?? $curFamily));
        }

        return $this->apply($r, $u, $name !== '' ? $name : null, $active);
    }

    private function apply(Request $r, User $u, ?string $name, ?bool $active): JsonResponse
    {
        $old = ['name' => $u->name, 'active' => $u->deleted_at === null];

        if ($active === false && $u->deleted_at === null) {
            // the firm must always have someone who can sign in with a password and fix the identity provider
            $others = User::permission(ModulePermissions::MANAGE_SECURITY_SETTINGS)->whereNull('platform_staff_id')->whereKeyNot($u->id)->exists();
            if (! $others && $u->hasPermissionTo(ModulePermissions::MANAGE_SECURITY_SETTINGS)) {
                return ScimTokenAuth::error(409, 'This is the last security administrator and cannot be deactivated from the identity provider.');
            }
        }
        if ($active === true && $u->deleted_at !== null) {
            try {
                app(Entitlements::class)->requireWithin('users');
            } catch (EntitlementException) {
                return ScimTokenAuth::error(403, 'The plan\'s user limit has been reached.');
            }
        }

        DB::transaction(function () use ($u, $name, $active) {
            if ($name !== null && $name !== '' && $name !== $u->name) {
                $u->forceFill(['name' => $name])->save();
            }
            if ($active === false && $u->deleted_at === null) {
                $u->tokens()->delete();   // API tokens stop with the person
                $u->delete();
            } elseif ($active === true && $u->deleted_at !== null) {
                $u->restore();
            }
        });

        $fresh = User::withTrashed()->find($u->id);
        $new = ['name' => $fresh->name, 'active' => $fresh->deleted_at === null];
        if ($old !== $new) {
            $this->audit($r, $new['active'] !== $old['active'] ? ($new['active'] ? 'scim_user_reactivated' : 'scim_user_deactivated') : 'scim_user_updated', $fresh, $old, $new);
        }

        return response()->json($this->view($fresh));
    }

    /** DELETE deactivates (never erases): the same soft delete the app uses. */
    public function destroy(Request $r, int $id): JsonResponse|Response
    {
        $u = $this->base()->whereKey($id)->first();
        if (! $u) {
            return ScimTokenAuth::error(404, 'User not found.');
        }
        $res = $this->apply($r, $u, null, false);

        return $res->getStatusCode() === 200 ? response()->noContent() : $res;
    }

    // ---- provisioning tokens (firm side, manage-security-settings) --------------------------------------------------

    private function tokenView(object $t): array
    {
        return ['id' => (int) $t->id, 'name' => $t->name, 'prefix' => 'scm_'.$t->prefix, 'default_role_id' => $t->default_role_id, 'last_used_at' => $t->last_used_at, 'revoked_at' => $t->revoked_at, 'created_at' => $t->created_at];
    }

    public function tokens(): JsonResponse
    {
        return response()->json(['data' => DB::table('scim_tokens')->orderByDesc('id')->get()->map(fn ($t) => $this->tokenView($t))->all(), 'base_url' => request()->getSchemeAndHttpHost().'/api/v1/scim/v2']);
    }

    public function createToken(CreateTokenRequest $r): JsonResponse
    {
        $d = $r->validated();
        $g = ScimTokenAuth::generate();
        $id = DB::table('scim_tokens')->insertGetId([
            'name' => $d['name'], 'prefix' => $g['prefix'], 'token_hash' => $g['hash'], 'default_role_id' => $d['default_role_id'] ?? null,
            'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        SecurityPolicy::audit('scim_token_created', 'scim_token', $id, [], ['name' => $d['name'], 'default_role_id' => $d['default_role_id'] ?? null]);

        return response()->json(['data' => $this->tokenView(DB::table('scim_tokens')->find($id)) + ['token' => $g['token']], 'notice' => 'Copy the token now: it is not shown again.'], 201);
    }

    public function revokeToken(Request $r, int $id): JsonResponse
    {
        $t = (object) (DB::table('scim_tokens')->find($id) ?? abort(404));
        if (! $t->revoked_at) {
            DB::table('scim_tokens')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            SecurityPolicy::audit('scim_token_revoked', 'scim_token', $id, ['name' => $t->name], []);
        }

        return response()->json(['data' => $this->tokenView(DB::table('scim_tokens')->find($id))]);
    }
}
