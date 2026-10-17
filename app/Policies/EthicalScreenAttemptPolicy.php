<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: attempts to cross a wall: a read-only log. */
class EthicalScreenAttemptPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::MANAGE_ETHICAL_SCREENS, null, null, null);
    }
}
