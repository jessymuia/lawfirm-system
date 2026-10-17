<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;
use Illuminate\Database\Eloquent\Model;

/** ENG-11: matters (cases). */
class CasesPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_CASES, P::CREATE_CASES, P::UPDATE_CASES, P::DELETE_CASES);
    }

    protected function caseIdOf(Model $record): ?int
    {
        return (int) $record->getKey();
    }
}
