<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: the document access log: read-only. */
class DocumentAccessPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::MANAGE_COMPLIANCE, null, null, null);
    }
}
