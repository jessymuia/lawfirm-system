<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: platform-support sessions (PLAT-8): the firm approves, denies and revokes; the platform creates them. */
class SupportAccessSessionPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::MANAGE_SUPPORT_ACCESS, null, P::MANAGE_SUPPORT_ACCESS, null);
    }
}
