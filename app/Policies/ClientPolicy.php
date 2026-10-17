<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;
use App\Matters\MatterAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** ENG-11: clients. */
class ClientPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_CLIENTS, P::CREATE_CLIENTS, P::UPDATE_CLIENTS, P::DELETE_CLIENTS);
    }

    /** A client is out of reach when a wall covers it (its matters follow MatterAccess on their own). */
    protected function reachable(User $user, Model $record): bool
    {
        return MatterAccess::wallFor((int) $user->getKey(), null, (int) $record->getKey()) === null;
    }
}
