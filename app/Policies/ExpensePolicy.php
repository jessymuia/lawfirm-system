<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: expenses. */
class ExpensePolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_EXPENSES, P::CREATE_EXPENSES, P::UPDATE_EXPENSES, P::DELETE_EXPENSES);
    }
}
