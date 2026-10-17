<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: time entries. */
class TimeEntryPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_TIME_ENTRIES, P::CREATE_TIME_ENTRIES, P::UPDATE_TIME_ENTRIES, P::DELETE_TIME_ENTRIES);
    }
}
