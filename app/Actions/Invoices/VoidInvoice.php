<?php

namespace App\Actions\Invoices;

use App\Actions\Concerns\Stamps;
use App\Models\Invoice;
use App\Models\User;

/** ENG-9: void an invoice (it stays on record as void, then is soft-deleted). Who may do it is the InvoicePolicy's call. */
class VoidInvoice
{
    use Stamps;

    public function handle(Invoice $invoice, ?User $actor): void
    {
        $invoice->workflow_status = 'void';
        $this->softDelete($invoice, $actor);
    }
}
