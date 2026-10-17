<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: people in the firm. */
class UserPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_USERS, P::CREATE_USERS, P::UPDATE_USERS, P::DELETE_USERS);
    }
}
