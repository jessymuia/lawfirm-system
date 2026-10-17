<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** FUN-9: a matter's parties are part of the matter: read with it, changed by whoever may update it. */
class MatterPartyPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_CASES, P::UPDATE_CASES, P::UPDATE_CASES, P::UPDATE_CASES);
    }
}
