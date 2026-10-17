<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/**
 * DATA-12: matter types. They are listed to whoever can list matters and added by saving a matter, so they follow the
 * matter permissions. Nothing deletes a type (it is archived instead), so delete and restore are refused to everyone.
 */
class CaseTypePolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_CASES, P::CREATE_CASES, P::UPDATE_CASES, null);
    }
}
