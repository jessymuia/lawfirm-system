<?php

namespace App\Actions\Trust;

use App\Actions\ActionException;
use App\Models\TrustTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ENG-9: void a trust entry. The entry is never edited or deleted (DATA-10): it is stamped void and leaves the balance.
 * A deposit can be voided only while the client's balance still covers it; the client's entries are locked while that
 * is checked, as posting does, so a disbursement and a void racing each other cannot overdraw the account.
 */
class VoidTrustEntry
{
    /** @return float the client's balance afterwards */
    public function handle(TrustTransaction $entry, ?User $actor): float
    {
        return DB::transaction(function () use ($entry, $actor) {
            TrustTransaction::where('client_id', $entry->client_id)->lockForUpdate()->get();
            $entry->refresh();
            if ($entry->voided_at) {
                throw new ActionException('Transaction is already voided');
            }
            if ($entry->type === 'deposit' && TrustTransaction::balanceForClient((int) $entry->client_id) - (float) $entry->amount < 0) {
                throw new ActionException('Cannot void this deposit: funds have already been disbursed');
            }
            $entry->voided_at = now();
            if ($actor) {
                $entry->voided_by = $actor->getKey();
                $entry->updated_by = $actor->getKey();
            }
            $entry->save();

            return TrustTransaction::balanceForClient((int) $entry->client_id);
        });
    }
}
