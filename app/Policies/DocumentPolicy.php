<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/** ENG-11: documents (no update: a change is a new version). */
class DocumentPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_DOCUMENTS, P::CREATE_DOCUMENTS, null, P::DELETE_DOCUMENTS);
    }
}
