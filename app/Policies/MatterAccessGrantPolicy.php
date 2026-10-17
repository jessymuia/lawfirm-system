<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: who may see a restricted matter (SEC-10). */
class MatterAccessGrantPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::MANAGE_MATTER_ACCESS, P::MANAGE_MATTER_ACCESS, P::MANAGE_MATTER_ACCESS, P::MANAGE_MATTER_ACCESS);
    }
}
