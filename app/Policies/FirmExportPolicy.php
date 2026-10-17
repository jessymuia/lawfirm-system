<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** ENG-11 / ENG-21: a firm-wide export belongs to the person who asked for it; nobody else may see or download it. */
class FirmExportPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::EXPORT_FIRM_DATA, P::EXPORT_FIRM_DATA, null, null);
    }

    protected function reachable(User $user, Model $record): bool
    {
        return (int) $record->getAttribute('requested_by') === (int) $user->getKey();
    }
}
