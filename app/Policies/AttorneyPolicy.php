<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: advocates assigned to a matter (part of the matter). */
class AttorneyPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_CASES, P::UPDATE_CASES, P::UPDATE_CASES, P::UPDATE_CASES);
    }
}
