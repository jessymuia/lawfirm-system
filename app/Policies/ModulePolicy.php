<?php

namespace App\Policies;

use App\Matters\MatterAccess;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * ENG-11: the base of every model policy. Deny by default.
 *
 * A policy states which permission each ability needs (`permissions()`); an ability it does not list, or lists as
 * null, is refused for everyone. Abilities on one record (view, update, delete, restore) also require the record to be
 * inside the matters the user may reach (SEC-10 access rule, SEC-11 ethical walls), so a policy check never reaches
 * past MatterAccess even where a query forgot its scope. forceDelete is never allowed through the API.
 */
abstract class ModulePolicy
{
    /** @return array<string, string|null> ability => permission */
    abstract protected function permissions(): array;

    public function viewAny(User $user): bool
    {
        return $this->has($user, 'viewAny');
    }

    public function create(User $user): bool
    {
        return $this->has($user, 'create');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->has($user, 'view') && $this->reachable($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->has($user, 'update') && $this->reachable($user, $record);
    }

    public function delete(User $user, Model $record): bool|Response
    {
        return $this->has($user, 'delete') && $this->reachable($user, $record);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->has($user, 'restore') && $this->reachable($user, $record);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    /** The permission an ability needs, or null when nobody may do it. */
    public function permissionFor(string $ability): ?string
    {
        return $this->permissions()[$ability] ?? null;
    }

    protected function has(User $user, string $ability): bool
    {
        $permission = $this->permissionFor($ability);

        return $permission !== null && $user->checkPermissionTo($permission);
    }

    /** Is this record inside what the user may reach? Records tied to a matter follow that matter. */
    protected function reachable(User $user, Model $record): bool
    {
        $caseId = $this->caseIdOf($record);

        return $caseId === null || MatterAccess::canAccessCase($user, $caseId);
    }

    protected function caseIdOf(Model $record): ?int
    {
        $caseId = $record->getAttribute('case_id');

        return $caseId === null ? null : (int) $caseId;
    }

    /** list/create/update/delete permissions in the order every module defines them. @return array<string, string|null> */
    protected static function crud(?string $list, ?string $create, ?string $update, ?string $delete, ?string $restore = null): array
    {
        return ['viewAny' => $list, 'view' => $list, 'create' => $create, 'update' => $update, 'delete' => $delete, 'restore' => $restore ?? $delete];
    }
}
