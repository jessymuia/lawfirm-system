<?php

namespace App\Actions\Payments;

use App\Actions\Concerns\Stamps;
use App\Models\Payment;
use App\Models\User;

/** ENG-9: take a payment off an invoice (soft delete, the invoice's paid total follows; the ledger trail stays, DATA-10). */
class RemovePayment
{
    use Stamps;

    public function handle(Payment $payment, ?User $actor): void
    {
        $this->softDelete($payment, $actor);
    }
}
