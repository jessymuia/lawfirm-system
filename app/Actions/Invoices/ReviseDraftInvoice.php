<?php

namespace App\Actions\Invoices;

use App\Actions\ActionException;
use App\Actions\Concerns\Stamps;
use App\Models\Invoice;
use App\Models\User;

/** ENG-9: change a draft's matter, client or dates. An issued invoice is part of the ledger and cannot change (DATA-10). */
class ReviseDraftInvoice
{
    use Stamps;

    /** @param array{case_id?: int, client_id?: int, invoice_date?: string, invoice_due_date?: string, currency?: string} $data */
    public function handle(Invoice $invoice, array $data, ?User $actor): Invoice
    {
        if ($invoice->workflow_status !== 'draft') {
            throw new ActionException('Only draft invoices can be edited');
        }
        $invoice->fill(array_intersect_key($data, array_flip(['case_id', 'client_id', 'invoice_date', 'invoice_due_date', 'currency'])));
        $this->stamp($invoice, $actor)->save();

        return $invoice;
    }
}
