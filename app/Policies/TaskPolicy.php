<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: tasks. */
class TaskPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_TASK, P::CREATE_TASK, P::UPDATE_TASK, P::DELETE_TASK);
    }
}
