<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: hearing types. */
class HearingTypePolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_HEARINGS, P::CREATE_HEARINGS, P::UPDATE_HEARINGS, P::DELETE_HEARINGS);
    }
}
