<?php

namespace App\Matters;

use App\Constants\ModulePermissions;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SEC-10 / SEC-11: the one place that decides which matters (cases) a user may see.
 *
 *   visible = ( access rule, when MATTER_ACCESS=restricted and the user has no bypass )
 *             MINUS everything an active ethical screen walls off  (a wall overrides every other path, including the bypass)
 *
 * Uses the query builder, never the models, so it cannot recurse into the global scopes it feeds.
 */
class MatterAccess
{
    public static function restricted(): bool
    {
        return config('matters.access') === 'restricted';
    }

    public static function walls(): WallRegistry
    {
        return app(WallRegistry::class);
    }

    /** The user the scopes apply to, or null when nothing is restricted for this call (no user, or no rule/wall applies). */
    public static function subject(): ?User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return null;   // console, queue and unauthenticated contexts have no user to restrict
        }

        return self::accessRuleApplies($user) || self::walls()->any($user->getKey()) ? $user : null;
    }

    public static function bypasses(User $user): bool
    {
        return $user->checkPermissionTo(ModulePermissions::ACCESS_ALL_MATTERS);
    }

    /** Whether the restricted-access rule limits this person (ENG-19: the search filter follows it). */
    public static function accessRuleApplies(User $user): bool
    {
        return self::restricted() && ! self::bypasses($user);
    }

    /** Sub-select of case ids the user may access. */
    public static function visibleCaseIds(User $user): Builder
    {
        $id = $user->getKey();
        $q = DB::table('cases')->select('cases.id');

        if (self::accessRuleApplies($user)) {
            $q->where(function ($q) use ($id) {
                $q->where('cases.assigned_to', $id)
                    ->orWhere('cases.created_by', $id)
                    ->orWhereIn('cases.id', DB::table('attorneys')->select('case_id')->where('advocate_id', $id)->whereNull('deleted_at'))
                    ->orWhereIn('cases.id', DB::table('matter_access_grants')->select('case_id')
                        ->where('user_id', $id)->whereNull('deleted_at')
                        ->where(fn ($g) => $g->whereNull('expires_at')->orWhere('expires_at', '>', now())));
            });
        }

        $w = self::walls()->for($id);
        if ($w['cases']) {
            $q->whereNotIn('cases.id', array_keys($w['cases']));
        }
        if ($w['clients']) {
            $q->whereNotIn('cases.client_id', array_keys($w['clients']));
        }

        return $q;
    }

    public static function canAccessCase(?User $user, ?int $caseId): bool
    {
        if ($caseId === null || ! $user) {
            return true;
        }
        if (! self::accessRuleApplies($user) && ! self::walls()->any($user->getKey())) {
            return true;
        }

        return self::visibleCaseIds($user)->where('cases.id', $caseId)->exists();
    }

    /** Is this user walled off from this matter (directly, or through its client)? Independent of who is logged in. */
    public static function isWalled(int $userId, int $caseId): bool
    {
        return self::wallFor($userId, $caseId) !== null;
    }

    /** @return array{screen_id: int, case_id: int|null, client_id: int|null}|null */
    public static function wallFor(int $userId, ?int $caseId, ?int $clientId = null): ?array
    {
        $w = self::walls()->for($userId);
        if ($caseId !== null && isset($w['cases'][$caseId])) {
            return ['screen_id' => $w['cases'][$caseId], 'case_id' => $caseId, 'client_id' => $clientId];
        }
        if ($clientId === null && $caseId !== null && $w['clients']) {
            $clientId = DB::table('cases')->where('id', $caseId)->value('client_id');
        }
        if ($clientId !== null && isset($w['clients'][(int) $clientId])) {
            return ['screen_id' => $w['clients'][(int) $clientId], 'case_id' => $caseId, 'client_id' => (int) $clientId];
        }

        return null;
    }
}
