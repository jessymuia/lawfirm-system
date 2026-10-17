<?php

namespace App\Matters;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * SEC-10: model trait. Adds the read scope and blocks WRITES that would attach a record to (or move it into) a matter the user
 * cannot access; the request validators only check that the case exists, not that the user may see it.
 *
 * Models override matterColumn() (default 'case_id') and optionally matterVia() (parent table).
 */
trait MatterScoped
{
    public static function bootMatterScoped(): void
    {
        static::addGlobalScope(new MatterScope(static::matterColumn(), static::matterVia()));

        if (static::matterVia() === null && static::matterColumn() === 'case_id') {
            static::saving(function ($model) {
                $user = MatterAccess::subject();
                if (! $user || ! ($model->isDirty('case_id') || ! $model->exists)) {
                    return;
                }
                $caseId = $model->case_id !== null ? (int) $model->case_id : null;
                if (! MatterAccess::canAccessCase($user, $caseId)) {
                    WallAttempts::record($user, 'write', $caseId, null, ['method' => request()->method(), 'path' => request()->path(), 'ip' => request()->ip()]);
                    throw new AuthorizationException('You do not have access to that matter.');
                }
            });
        }
    }

    /** Column holding the matter id (or the parent's id when matterVia() is set). */
    public static function matterColumn(): string
    {
        return 'case_id';
    }

    /** Parent table that carries case_id, when this model reaches its matter through a parent. */
    public static function matterVia(): ?string
    {
        return null;
    }
}
