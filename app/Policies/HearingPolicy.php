<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: hearings. */
class HearingPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_HEARINGS, P::CREATE_HEARINGS, P::UPDATE_HEARINGS, P::DELETE_HEARINGS);
    }
}
