<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: ethical walls (SEC-11); lifted, never deleted. */
class EthicalScreenPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::MANAGE_ETHICAL_SCREENS, P::MANAGE_ETHICAL_SCREENS, P::MANAGE_ETHICAL_SCREENS, null);
    }
}
