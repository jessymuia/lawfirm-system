<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: court types. */
class CourtTypePolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_COURTS, P::CREATE_COURTS, P::UPDATE_COURTS, P::DELETE_COURTS);
    }
}
