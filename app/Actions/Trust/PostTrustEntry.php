<?php

namespace App\Actions\Trust;

use App\Actions\ActionException;
use App\Models\TrustTransaction;

/** ENG-9: a deposit to or disbursement from a client's trust money. A disbursement can never overdraw the client. */
class PostTrustEntry
{
    /** @param array{client_id: int, case_id?: ?int, type: string, amount: float|int|string, description: string, reference_number?: ?string} $data */
    public function handle(array $data, int $recordedBy): TrustTransaction
    {
        try {
            return TrustTransaction::post((int) $data['client_id'], isset($data['case_id']) ? (int) $data['case_id'] : null, (string) $data['type'],
                (float) $data['amount'], (string) $data['description'], $data['reference_number'] ?? null, $recordedBy);
        } catch (\RuntimeException $e) {
            throw new ActionException($e->getMessage());
        }
    }
}
