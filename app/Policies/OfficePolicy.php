<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** FUN-18: the firm's structure is managed by whoever holds manage-organisation (everyone may read the names, see /organisation/options). */
class OfficePolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::MANAGE_ORGANISATION, P::MANAGE_ORGANISATION, P::MANAGE_ORGANISATION, P::MANAGE_ORGANISATION);
    }
}
