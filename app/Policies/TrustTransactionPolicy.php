<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** ENG-11: the trust ledger is append-only: no update, no delete; a mistake is voided by a reversing entry. */
class TrustTransactionPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_TRUST_TRANSACTIONS, P::CREATE_TRUST_TRANSACTIONS, null, null) + ['void' => P::VOID_TRUST_TRANSACTIONS];
    }

    public function void(User $user, Model $record): bool
    {
        return $this->has($user, 'void') && $this->reachable($user, $record);
    }
}
